<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosSalesSource
{
    public function keys(array $profile, string $from, string $to, ?array $window = null)
    {
        $db = DB::connection(config('pos_sync.connection'));
        $base = $db->table('pos_sale')->where('pos_sale.fcompanyid', $profile['company'])
            ->where('pos_sale.fsiteid', $profile['site']);
        $start = app(PosSalesEligibility::class)->startDate($profile);
        if (!$start) return $base->whereRaw('1 = 0')->select('pos_sale.fpubid', 'pos_sale.frecno')->distinct();
        $base->where('pos_sale.fsale_date', '>=', str_replace('-', '', $start));
        if ($window) {
            $changed = null;
            foreach (['pos_sale', 'pos_sale_product', 'pos_sale_cancel'] as $table) {
                $part = $db->table($table)->select('fcompanyid', 'fpubid', 'frecno')
                    ->where('fcompanyid', $profile['company'])
                    ->whereBetween('_sync_timestamp', [$window['since'], $window['until']]);
                $changed = $changed ? $changed->union($part) : $part;
            }
            $base->leftJoinSub($changed, 'changed_receipts', function ($join) {
                $join->on('changed_receipts.fcompanyid', '=', 'pos_sale.fcompanyid')
                    ->on('changed_receipts.fpubid', '=', 'pos_sale.fpubid')
                    ->on('changed_receipts.frecno', '=', 'pos_sale.frecno');
            });
            $due = \Illuminate\Support\Facades\Schema::hasTable('pos_sync_exceptions')
                ? DB::table('pos_sync_exceptions')->where('entity_id', $profile['entity_id'])
                    ->where('store_branch_id', $profile['branch_id'])->whereNull('resolved_at')
                    ->where('retry_at', '<=', now())->orderBy('retry_at')->limit(100)->pluck('source_identity')->all() : [];
            $base->where(function ($query) use ($due, $profile) {
                $query->whereNotNull('changed_receipts.frecno');
                foreach ($due as $identity) {
                    $identity = json_decode($identity, true);
                    if ($identity[0] !== $profile['company'] || $identity[1] !== $profile['site']) continue;
                    $query->orWhere(fn ($q) => $q->where('pos_sale.fpubid', $identity[2])->where('pos_sale.frecno', $identity[3]));
                }
            });
        } else {
            $base->whereBetween('fsale_date', [str_replace('-', '', $from), str_replace('-', '', $to)]);
        }
        return $base->select('pos_sale.fpubid', 'pos_sale.frecno')->distinct();
    }

    public function receipts(array $profile, string $from, string $to, ?array $window = null): \Generator
    {
        $db = DB::connection(config('pos_sync.connection'));
        $base = $db->table('pos_sale')->where('fcompanyid', $profile['company'])->where('fsiteid', $profile['site']);
        $keys = $this->keys($profile, $from, $to, $window)->orderBy('fpubid')->orderBy('frecno')->get(); // Close the SQL Server result before receipt transactions (MARS).
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
                $packet['rows'] = $mapper->map($headers[0], $lines, $profile);
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
