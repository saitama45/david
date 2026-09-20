<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PosScanLoop extends Command
{
    protected $signature = 'pos:scan-loop {--once}';
    protected $description = 'Run POS discovery every ten seconds and daily reconciliation; workers run separately';

    public function handle(): int
    {
        do {
            $started = microtime(true);
            try {
                $code = $this->call('pos:sync-sales', ['--scheduled' => true]);
                Cache::put('pos-sync:scanner-heartbeat', ['at' => now()->toIso8601String(), 'success' => $code === 0], now()->addMinutes(10));
                if ($code === 0 && config('pos_sync.enabled') && now()->hour >= 1) {
                    $key = 'pos-sync:daily-reconciliation:'.now()->format('Y-m-d');
                    if (Cache::add($key, true, now()->addDays(2))) {
                        if ($this->call('pos:reconcile-sales') !== 0) Cache::forget($key);
                    }
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            if ($this->option('once')) break;
            usleep((int) (max(0.1, 10 - (microtime(true) - $started)) * 1000000));
        } while (true);
        return self::SUCCESS;
    }
}
