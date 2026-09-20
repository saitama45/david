<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ReconcilePosSales extends Command
{
    protected $signature = 'pos:reconcile-sales {--from=} {--to=}';
    protected $description = 'Recheck recent POS sales independently of source change timestamps through Work Queue';

    public function handle(): int
    {
        if (!config('pos_sync.enabled')) {
            $this->info('Automatic POS reconciliation is disabled.');
            return self::SUCCESS;
        }
        $failed = false;
        foreach (array_keys(config('pos_sync.profiles', [])) as $name) {
            $code = $this->call('pos:sync-sales', ['--profile' => $name, '--apply' => true, '--only-changes' => true,
                '--from' => $this->option('from') ?: now()->subDays(7)->format('Y-m-d'),
                '--to' => $this->option('to') ?: now()->format('Y-m-d')]);
            $failed = $failed || $code !== self::SUCCESS;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
