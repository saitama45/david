<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosSalesSource
{
    /** Only replication arrivals can authorize an automatic Work Queue entry. */
    public function arrivalWindow(array $profile, array $window, ?string $previous): ?array
    {
        $fresh = $window;
        if ($previous) $fresh['since'] = $previous;
        $fresh['after'] = $previous;
        $source = DB::connection(config('pos_sync.connection'));
        $referenceChanged = false;
        foreach (['mst_account', 'mst_discount', 'mst_product', 'mst_pricelevel'] as $table) {
            // Some store replicas do not supply optional reference tables.
            if (!$source->getSchemaBuilder()->hasColumn($table, '_sync_timestamp')) continue;
            $query = $source->table($table)->where('fcompanyid', $profile['company'])
                ->whereBetween('_sync_timestamp', [$fresh['since'], $fresh['until']]);
            if ($previous) $query->where('_sync_timestamp', '>', $previous);
            if ($query->exists()) $referenceChanged = true;
        }
        if (!$referenceChanged && !$this->keys($profile, '', '', $fresh)->exists()) return null;
        return $window + ['source_only' => true, 'reference_changed' => $referenceChanged];
    }

    public function keys(array $profile, string $from, string $to, ?array $window = null)
    {
        $db = DB::connection(config('pos_sync.connection'));
        $base = $db->table('pos_sale')->where('pos_sale.fcompanyid', $profile['company'])
            ->where('pos_sale.fsiteid', $profile['site']);
        $start = app(PosSalesEligibility::class)->startDate($profile);
        if (!$start) return $base->whereRaw('1 = 0')->select('pos_sale.fpubid', 'pos_sale.frecno')->distinct();
        $base->where('pos_sale.fsale_date', '>=', str_replace('-', '', $start));
        if ($window && !empty($window['reference_changed'])) {
            // Reference tables have company scope, not store scope. Recheck the
            // eligible store's sales only when a replicated reference changed.
        } elseif ($window) {
            $changed = null;
            foreach (['pos_sale', 'pos_sale_product', 'pos_sale_cancel'] as $table) {
                $part = $db->table($table)->select('fcompanyid', 'fpubid', 'frecno')
                    ->where('fcompanyid', $profile['company'])
                    ->whereBetween('_sync_timestamp', [$window['since'], $window['until']]);
                if (!empty($window['after'])) $part->where('_sync_timestamp', '>', $window['after']);
                $changed = $changed ? $changed->union($part) : $part;
            }
            $base->leftJoinSub($changed, 'changed_receipts', function ($join) {
                $join->on('changed_receipts.fcompanyid', '=', 'pos_sale.fcompanyid')
                    ->on('changed_receipts.fpubid', '=', 'pos_sale.fpubid')
                    ->on('changed_receipts.frecno', '=', 'pos_sale.frecno');
            });
            $base->whereNotNull('changed_receipts.frecno');
        } else {
            $base->whereBetween('fsale_date', [str_replace('-', '', $from), str_replace('-', '', $to)]);
        }
        return $base->select('pos_sale.fpubid', 'pos_sale.frecno')->distinct();
    }

    /** All changed and due receipt identities; chunks bound SQL parameters, not the result count. */
    public function candidateKeys(array $profile, string $from, string $to, ?array $window = null): \Illuminate\Support\Collection
    {
        $keys = $this->keys($profile, $from, $to, $window)->get();
        $start = app(PosSalesEligibility::class)->startDate($profile);
        if (!$window || !empty($window['source_only']) || !$start || !\Illuminate\Support\Facades\Schema::hasTable('pos_sync_exceptions')) return $keys;
        DB::table('pos_sync_exceptions')->where('entity_id', $profile['entity_id'])
            ->where('store_branch_id', $profile['branch_id'])->whereNull('resolved_at')->where('retry_at', '<=', now())
            ->chunkById(400, function ($exceptions) use (&$keys, $profile, $start) {
                $identities = $exceptions->map(fn ($e) => json_decode($e->source_identity, true))
                    ->filter(fn ($i) => is_array($i) && count($i) === 4 && $i[0] === $profile['company'] && $i[1] === $profile['site']);
                if ($identities->isEmpty()) return;
                $due = DB::connection(config('pos_sync.connection'))->table('pos_sale')
                    ->where('fcompanyid', $profile['company'])->where('fsiteid', $profile['site'])
                    ->where('fsale_date', '>=', str_replace('-', '', $start))
                    ->where(function ($query) use ($identities) {
                        foreach ($identities as $i) {
                            $query->orWhere(fn ($q) => $q->where('fpubid', $i[2])->where('frecno', $i[3]));
                        }
                    })->select('fpubid', 'frecno')->distinct()->get();
                $keys = $keys->concat($due);
            });
        return $keys->unique(fn ($k) => json_encode([$k->fpubid, $k->frecno]))->values();
    }

    public function receipts(array $profile, string $from, string $to, ?array $window = null): \Generator
    {
        $db = DB::connection(config('pos_sync.connection'));
        $base = $db->table('pos_sale')->where('fcompanyid', $profile['company'])->where('fsiteid', $profile['site']);
        $keys = $this->candidateKeys($profile, $from, $to, $window); // Close SQL Server results before receipt transactions (MARS).
        foreach ($keys as $key) {
            $identity = [$profile['company'], $profile['site'], $key->fpubid, $key->frecno];
            $packet = ['identity' => $identity, 'key' => hash('sha256', json_encode($identity)), 'error' => null];
            try {
                $headers = (clone $base)->where('fpubid', $key->fpubid)->where('frecno', $key->frecno)
                    ->get()->map(fn ($r) => (array) $r)->all();
                $lineQuery = $db->table('pos_sale_product')->where('fcompanyid', $profile['company'])
                    ->where('fpubid', $key->fpubid)->where('frecno', $key->frecno);
                $lines = $lineQuery->get()->map(fn ($r) => (array) $r)->all();
                foreach (array_merge($headers, $lines) as $row) {
                    if (empty($row['_sync_timestamp']) || \Carbon\Carbon::parse($row['_sync_timestamp'])
                        ->gt(now()->subSeconds(config('pos_sync.settle_seconds', 5)))) {
                        throw new RuntimeException('Source receipt is still syncing or has no sync timestamp.');
                    }
                }
                $mapper = new PosReceiptMapper;
                $headers = $mapper->uniqueRows($headers, 'frecno');
                if (count($headers) !== 1) {
                    throw new RuntimeException('Source receipt header is missing or ambiguous.');
                }
                $header = $headers[0];
                $packet['excluded'] = ($header['fpost_flag'] ?? '') === '0' || ($header['fvoid_flag'] ?? '') === '1'
                    || ($header['freturn_flag'] ?? '') === '1' || trim($header['ftrx_no'] ?? '') === '0';
                $lines = $mapper->uniqueRows($lines, 'fseqno');
                foreach ($lines as $line) {
                    // Orders can open on one terminal and settle on another.
                    // Company/publisher/record is the join; the final header
                    // supplies the reporting terminal and business date.
                    if ($line['fstatus_flag'] === '1' && $line['fsiteid'] !== $profile['site']) {
                        throw new RuntimeException('Active receipt line belongs to another branch.');
                    }
                }
                // Cancellation rows include historical removed/replaced lines.
                // Current header flags and line statuses determine what was sold.
                $corrections = [];
                $packet['rows'] = $mapper->map($headers[0], $lines, $profile, $corrections);
                $packet['corrections'] = $corrections;
                $packet['hash'] = hash('sha256', json_encode($packet['rows'], JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                // Connection/schema failures must fail the job, not masquerade as bad receipts.
                if ($e instanceof \Illuminate\Database\QueryException) {
                    throw $e;
                }
                $packet['error'] = $e->getMessage();
            }
            yield $packet;
        }
    }
}
