<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\PosSalesSource;
use App\Services\PosSalesSync;
use App\Support\EntityContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncPosSales extends Command
{
    protected $signature = 'pos:sync-sales {--profile= : Profile in config/pos_sync.php}
        {--from= : First sales date (YYYY-MM-DD)} {--to= : Last sales date (YYYY-MM-DD)}
        {--apply : Queue posting; otherwise preview only} {--scheduled : Queue enabled profiles}
        {--output= : Write preview rows and reconciliation results to CSV}
        {--only-changes : Queue only actionable sales or changed issues}';

    protected $description = 'Preview or queue POS sales through the shared store transaction processor';

    public function handle(PosSalesSync $sync, PosSalesSource $source): int
    {
        try {
            $scheduled = (bool) $this->option('scheduled');
            if ($scheduled && !config('pos_sync.enabled')) {
                $this->info('Scheduled POS sync is disabled.');
                return self::SUCCESS;
            }
            $apply = $scheduled || $this->option('apply');
            if ($apply && (!Schema::hasTable('pos_sync_receipts') || ($scheduled && !Schema::hasTable('pos_sync_cursors')))) {
                throw new \RuntimeException('Run the POS sync tracking migration before posting.');
            }
            if ($apply) {
                $sync->dispatchPending();
            }
            $from = $this->option('from') ?: now()->subDays(max(1, config('pos_sync.lookback_days', 7)))->format('Y-m-d');
            $to = $this->option('to') ?: now()->format('Y-m-d');
            foreach ([$from, $to] as $value) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    throw new \InvalidArgumentException('Dates must be valid YYYY-MM-DD calendar dates.');
                }
            }
            if ($from > $to) {
                throw new \InvalidArgumentException('--from must not be later than --to.');
            }
            $names = $scheduled ? array_keys(config('pos_sync.profiles', [])) : [$this->option('profile')];
            if (!$scheduled && !$names[0]) {
                throw new \InvalidArgumentException('Specify --profile. Configure company/site/branch mappings in config/pos_sync.php.');
            }
            if (!$names) throw new \RuntimeException('No POS profiles configured. Set POS_SYNC_PROFILES first.');
            $report = null;
            if ($this->option('output') && !$apply) {
                $report = fopen($this->option('output'), 'x');
                if (!$report) {
                    throw new \RuntimeException('Cannot create preview output; choose a new filename.');
                }
                fputcsv($report, ['Source Receipt', 'Status', 'Reason', 'Product ID', 'Branch', 'Date', 'Posted', 'TM#',
                    'Receipt No', 'Base Qty', 'Qty', 'Unit', 'Price', 'Discount', 'Line Total', 'Net Total', 'Take Out']);
            }
            try {
                foreach ($names as $name) {
                    $profile = $sync->profile($name, (bool) $apply);
                    $eligibleFrom = app(\App\Services\PosSalesEligibility::class)->startDate($profile);
                    if (!$eligibleFrom) {
                        $this->info("{$name}: not Go-Live; automatic sales posting excluded.");
                        continue;
                    }
                    app(EntityContext::class)->runAs((int) $profile['entity_id'], function () use ($sync, $source, $name, $profile, $from, $to, $apply, $report, $scheduled) {
                        if ($apply) {
                            (new \App\Services\SalesPostingLedger)->requireReady();
                            Cache::lock('pos-sync:enqueue:'.hash('sha256', $name), 60)->block(5, function () use ($sync, $source, $name, $profile, $from, $to, $scheduled) {
                                $pending = ImportLog::where('type', 'pos_sales')->where('original_filename', 'like', "POS {$name} %")
                                    ->whereIn('status', ['pending', 'processing'])->exists();
                                if ($pending) {
                                    $this->info("{$name}: an existing POS sync is pending or processing.");
                                    return;
                                }
                                $window = null;
                                if ($scheduled) {
                                    $previous = DB::table('pos_sync_cursors')->where('profile_key', $sync->cursorKey($profile))->value('synced_until');
                                    $window = [
                                        'since' => ($previous ? \Carbon\Carbon::parse($previous)->subSeconds(30) : (isset($profile['start_date']) ? \Carbon\Carbon::parse($profile['start_date'])->startOfDay() : now()->subDays(config('pos_sync.lookback_days', 7))))->format('Y-m-d H:i:s'),
                                        'until' => now()->subSeconds(config('pos_sync.settle_seconds', 5))->format('Y-m-d H:i:s'),
                                    ];
                                }
                                if (($scheduled || $this->option('only-changes')) && !$sync->hasPendingWork($profile, $from, $to, $window)) {
                                    if ($window) $sync->advanceCursor($profile, $window['until']);
                                    $this->info("{$name}: no actionable changes; no Work Queue entry created.");
                                    return;
                                }
                                $log = $sync->enqueue($name, $profile, $from, $to, $window);
                                $this->info("{$name}: queued as Work Queue #{$log->id}.");
                            });
                            return;
                        }
                        $counts = [];
                        foreach ($source->receipts($profile, $from, $to) as $packet) {
                            $result = $sync->process($packet, $profile);
                            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
                            if ($report) {
                                foreach ($packet['rows'] ?? [[]] as $row) {
                                    fputcsv($report, array_merge([implode('/', $packet['identity']), $result['status'], $result['reason']],
                                        array_map(fn ($k) => $row[$k] ?? '', ['product_id', 'branch', 'date', 'posted', 'tm', 'receipt_no',
                                            'base_qty', 'qty', 'uom', 'price', 'discount', 'line_total', 'net_total', 'take_out'])));
                                }
                            }
                        }
                        $this->info($name.' preview (no database writes): '.json_encode($counts));
                    });
                }
            } finally {
                if (is_resource($report)) {
                    fclose($report);
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
