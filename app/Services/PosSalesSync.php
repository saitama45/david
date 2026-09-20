<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\POSMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Support\EntityContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PosSalesSync
{
    /** POS has a dedicated worker but remains visible in the same Work Queue. */
    public function dispatchPending(): void
    {
        Cache::lock('pos-sync:dispatch', 30)->block(5, function () {
            foreach (ImportLog::withoutEntityScope()->where('type', 'pos_sales')->where('status', 'pending')->orderBy('id')->get() as $log) {
                if (!app(ImportQueueService::class)->queueJobForImportLogId($log->id)) {
                    app(EntityContext::class)->runAs((int) $log->entity_id, fn () =>
                        \App\Jobs\PosSalesSyncJob::dispatch($log->source_file_path, $log->id));
                }
            }
        });
    }

    public function profile(string $name, bool $apply): array
    {
        $profile = config("pos_sync.profiles.{$name}");
        if (!is_array($profile)) {
            throw new InvalidArgumentException("Unknown POS profile '{$name}'. Configure config/pos_sync.php first.");
        }
        foreach (['company', 'site', 'entity_id', 'branch_id', 'user_id'] as $key) {
            if (!isset($profile[$key])) {
                throw new InvalidArgumentException("POS profile requires {$key}.");
            }
        }
        $branch = StoreBranch::withoutEntityScope()->where('entity_id', $profile['entity_id'])->find($profile['branch_id']);
        if (!$branch || !$branch->is_active || (isset($profile['branch_code']) && $branch->branch_code !== $profile['branch_code'])
            || !DB::table('users')->where('id', $profile['user_id'])->exists()) {
            throw new InvalidArgumentException('Invalid branch/entity mapping or queue owner.');
        }
        return $profile;
    }

    public function cursorKey(array $profile): string
    {
        return hash('sha256', json_encode([config('pos_sync.connection'), $profile['company'], $profile['site'], $profile['entity_id'], $profile['branch_id'],
            app(PosSalesEligibility::class)->startDate($profile)]));
    }

    public function advanceCursor(array $profile, string $until): void
    {
        $key = $this->cursorKey($profile);
        DB::transaction(function () use ($key, $until) {
            $existing = DB::table('pos_sync_cursors')->where('profile_key', $key)->lockForUpdate()->first();
            if (!$existing || $existing->synced_until < $until) {
                DB::table('pos_sync_cursors')->updateOrInsert(['profile_key' => $key], [
                    'synced_until' => $until, 'updated_at' => now(), 'created_at' => $existing->created_at ?? now(),
                ]);
            }
        });
    }

    public function enqueue(string $name, array $profile, string $from, string $to, ?array $window = null): ImportLog
    {
        return app(EntityContext::class)->runAs((int) $profile['entity_id'], function () use ($name, $profile, $from, $to, $window) {
            $path = 'imports/pos-sales/'.Str::uuid().'.json';
            if (!Storage::put($path, json_encode(compact('name', 'profile', 'from', 'to', 'window'), JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Could not save POS sync request.');
            }
            try {
                $log = DB::transaction(function () use ($name, $profile, $from, $to, $path) {
                    $log = ImportLog::create([
                        'entity_id' => $profile['entity_id'], 'user_id' => $profile['user_id'],
                        'type' => 'pos_sales', 'original_filename' => "POS {$name} {$from} to {$to}",
                        'source_file_path' => $path, 'status' => 'pending',
                    ]);
                    $log->storeBranches()->sync([$profile['branch_id']]);
                    return $log;
                });
            } catch (\Throwable $e) {
                Storage::delete($path);
                throw $e;
            }
            $this->dispatchPending();
            return $log;
        });
    }

    /** Called inside the job's entity context. Preview performs reads only. */
    public function process(array $packet, array $profile, ?int $logId = null): array
    {
        if ($logId) (new SalesPostingLedger)->requireReady();
        $work = function () use ($packet, $profile, $logId) {
            $branch = StoreBranch::where('entity_id', $profile['entity_id'])->whereKey($profile['branch_id']);
            if ($logId) {
                $branch->lockForUpdate();
            }
            $branch->firstOrFail();
            $eligibleFrom = app(PosSalesEligibility::class)->startDate($profile);
            if (!$eligibleFrom || (!empty($packet['rows'][0]['date']) && $packet['rows'][0]['date'] < $eligibleFrom)) {
                return ['status' => 'ineligible', 'reason' => 'Store is not Go-Live or the sale predates its eligible start date; no posting or inventory deduction.'];
            }
            if ($logId) (new SalesPostingLedger)->requireReady();
            $tracked = \Illuminate\Support\Facades\Schema::hasTable('pos_sync_receipts')
                ? DB::table('pos_sync_receipts')->where('source_key', $packet['key'])->first()
                : null;
            if ($tracked && ((int) $tracked->entity_id !== (int) $profile['entity_id']
                || (int) $tracked->store_branch_id !== (int) $profile['branch_id'])) {
                return ['status' => 'review', 'reason' => 'Source receipt was previously mapped to another entity or branch.'];
            }
            if ($packet['error']) {
                if (!$tracked && !empty($packet['excluded'])) return ['status' => 'excluded', 'reason' => $packet['error']];
                return ['status' => 'review', 'reason' => ($tracked ? 'Previously imported receipt: ' : '').$packet['error']];
            }
            if ($tracked) {
                $posting = \Illuminate\Support\Facades\Schema::hasTable('sales_postings') ? DB::table('sales_postings')->where('store_transaction_id', $tracked->store_transaction_id)->first() : null;
                if ($posting && $posting->source === 'correction' && (new SalesPostingLedger)->intact($posting)
                    && hash_equals($posting->rows_hash, (new SalesPostingLedger)->rowsHash($packet['rows']))
                    && hash_equals($posting->receipt_key, \App\Support\StoreReceiptIdentity::key($profile['entity_id'], $profile['branch_id'],
                        $packet['rows'][0]['date'], $packet['rows'][0]['receipt_no'], $packet['rows'][0]['tm']))) {
                    if ($logId) DB::table('pos_sync_receipts')->where('id', $tracked->id)->update(['source_hash' => $packet['hash'], 'updated_at' => now()]);
                    return ['status' => 'reconciled', 'reason' => 'Audited correction now agrees with POS; no additional deduction.'];
                }
                return $posting && (new SalesPostingLedger)->intact($posting) && hash_equals($tracked->source_hash, $packet['hash']) && hash_equals($posting->rows_hash, (new SalesPostingLedger)->rowsHash($packet['rows']))
                    ? ['status' => 'unchanged', 'reason' => 'Already synchronized.']
                    : ['status' => 'review', 'reason' => 'Imported source changed or destination is missing. Reconcile original inventory movements before reposting.'];
            }
            $first = $packet['rows'][0];
            $ledger = new SalesPostingLedger;
            $existing = $ledger->existing($profile['branch_id'], $first['date'], $first['receipt_no'], $first['tm']);
            if ($existing) {
                $posting = \Illuminate\Support\Facades\Schema::hasTable('sales_postings') ? DB::table('sales_postings')->where('store_transaction_id', $existing->id)->first() : null;
                if (\Illuminate\Support\Facades\Schema::hasTable('pos_sync_receipts') && DB::table('pos_sync_receipts')->where('store_transaction_id', $existing->id)->exists()) {
                    return ['status' => 'review', 'reason' => 'Receipt is already linked to another source identity; reconcile the source before posting.'];
                }
                if (!$posting || !$ledger->intact($posting) || !hash_equals($posting->rows_hash, $ledger->rowsHash($packet['rows']))) {
                    return ['status' => 'review', 'reason' => 'Existing manual receipt differs or lacks verified inventory movements. Reconcile before linking; no new deduction.'];
                }
                if (!$logId) return ['status' => 'ready', 'reason' => 'Matching manual receipt and stock movements; will link without a new deduction.'];
                DB::table('sales_postings')->where('id', $posting->id)->update(['pos_verified_at' => now(), 'updated_at' => now()]);
                $this->track($packet, $profile, $logId, $existing->id);
                return ['status' => 'linked', 'reason' => 'Existing manual receipt and stock movements verified; no additional deduction.'];
            }
            foreach ($packet['rows'] as $row) {
                $pos = POSMasterfile::whereRaw('UPPER(POSCode) = ?', [strtoupper($row['product_id'])])->first();
                if (!$pos) {
                    return ['status' => 'review', 'reason' => 'Missing POS masterfile: '.$row['product_id']];
                }
            }
            if (!$logId) {
                $preview = new StoreTransactionReceiptProcessor(null, 'pos');
                return $preview->previewReceiptGroup(collect($packet['rows']))
                    ? ['status' => 'ready', 'reason' => 'Receipt, BOM and inventory mapping checks passed; no writes.']
                    : ['status' => 'review', 'reason' => implode(' | ', array_unique(array_column($preview->getSkippedRows(), 'reason')))];
            }
            $processor = new StoreTransactionReceiptProcessor(null, 'pos');
            $transaction = $processor->processReceiptGroup(collect($packet['rows']));
            if (!$transaction) {
                return ['status' => 'review', 'reason' => implode(' | ', array_unique(array_column($processor->getSkippedRows(), 'reason')))];
            }
            $this->track($packet, $profile, $logId, $transaction->id);
            return ['status' => 'imported', 'reason' => 'Receipt and inventory processing committed.'];
        };
        $execute = function () use ($work, $packet, $profile, $logId) {
        $result = $work();
        if ($logId && $result['status'] !== 'ineligible') {
            $exceptions = DB::table('pos_sync_exceptions')->where('source_key', $packet['key']);
            if ($result['status'] === 'review') {
                $old = $exceptions->first();
                DB::table('pos_sync_exceptions')->updateOrInsert(['source_key' => $packet['key']], [
                    'entity_id' => $profile['entity_id'], 'store_branch_id' => $profile['branch_id'],
                    'source_identity' => json_encode($packet['identity']), 'reason' => $result['reason'],
                    'attempts' => ($old->attempts ?? 0) + 1, 'retry_at' => now()->addMinutes(5),
                    'resolved_at' => null, 'created_at' => $old->created_at ?? now(), 'updated_at' => now(),
                ]);
            } else {
                $exceptions->update(['resolved_at' => now(), 'updated_at' => now()]);
            }
        }
        return $result;
        };
        return $logId ? DB::transaction($execute) : $execute();
    }

    private function track(array $packet, array $profile, int $logId, int $transactionId): void
    {
        DB::table('pos_sync_receipts')->insert([
            'entity_id' => $profile['entity_id'], 'store_branch_id' => $profile['branch_id'],
            'source_key' => $packet['key'], 'source_identity' => json_encode($packet['identity'], JSON_THROW_ON_ERROR),
            'source_hash' => $packet['hash'], 'store_transaction_id' => $transactionId,
            'import_log_id' => $logId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
