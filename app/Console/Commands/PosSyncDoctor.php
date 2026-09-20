<?php

namespace App\Console\Commands;

use App\Services\PosSalesSync;
use App\Support\EntityContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosSyncDoctor extends Command
{
    protected $signature = 'pos:doctor';
    protected $description = 'Read-only checks for POS sync configuration, migrations and source availability';

    public function handle(PosSalesSync $sync): int
    {
        try {
            foreach (['pos_sync_receipts','pos_sync_cursors','sales_postings','pos_sync_exceptions','sales_posting_corrections','jobs','import_logs'] as $table) {
                if (!Schema::hasTable($table)) throw new \RuntimeException("Missing application table: {$table}");
            }
            if (!Schema::hasColumn('pos_sync_exceptions', 'packet_fingerprint')) {
                throw new \RuntimeException('Run migrations to enable quiet POS retries (missing packet_fingerprint).');
            }
            if ((int) config('queue.connections.database.retry_after') <= 3600) throw new \RuntimeException('DB_QUEUE_RETRY_AFTER must exceed the 3600-second worker timeout.');
            $names = array_keys(config('pos_sync.profiles', []));
            if (!$names) throw new \RuntimeException('No store mappings found in config/pos_sync_profiles.json (or its optional environment override).');
            $seen = [];
            $eligibleCount = 0;
            foreach ($names as $name) {
                $profile = $sync->profile($name, false);
                $identity = $profile['company'].'/'.$profile['site'];
                if (isset($seen[$identity])) throw new \RuntimeException("Duplicate source mapping: {$identity}");
                $seen[$identity] = true;
                $date = $profile['start_date'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || \App\Support\StoreReceiptIdentity::date($date) !== $date) throw new \RuntimeException("{$name}: set an explicit valid start_date.");
                $eligibleFrom = app(\App\Services\PosSalesEligibility::class)->startDate($profile);
                if (!$eligibleFrom) {
                    $this->line("{$name}: not Go-Live; excluded from automatic sales processing.");
                    continue;
                }
                $eligibleCount++;
                $owner = \App\Models\User::findOrFail($profile['user_id']);
                if (!$owner->accessibleEntities()->whereKey($profile['entity_id'])->exists()) throw new \RuntimeException("{$name}: Work Queue owner cannot access the entity.");
                app(EntityContext::class)->runAs((int) $profile['entity_id'], fn () => (new \App\Services\SalesPostingLedger)->requireReady());
                $latest = DB::connection(config('pos_sync.connection'))->table('pos_sale')
                    ->where('fcompanyid', $profile['company'])->where('fsiteid', $profile['site'])->max('_sync_timestamp');
                if (!$latest) throw new \RuntimeException("{$name}: no source sales found for this mapping.");
                $this->line("{$name}: branch {$profile['branch_code']}; eligible sales from {$eligibleFrom}; last source arrival {$latest}");
            }
            foreach (['pos_sale_product','pos_sale_cancel','mst_account','mst_product','mst_discount','mst_price_level'] as $table) {
                if (!Schema::connection(config('pos_sync.connection'))->hasTable($table)) throw new \RuntimeException("Missing POS source table: {$table}");
            }
            $this->info(count($names).' mappings checked; '.$eligibleCount.' Go-Live stores eligible. Automatic discovery: '.(config('pos_sync.enabled') ? 'enabled' : 'disabled').'. Source freshness and recipe exceptions must still be monitored.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
