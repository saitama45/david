<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PosScanLoop extends Command
{
    protected $signature = 'pos:scan-loop {--once}';
    protected $description = 'Check POS replication arrivals every ten seconds; workers run separately';

    public function handle(): int
    {
        do {
            $started = microtime(true);
            try {
                $code = $this->call('pos:sync-sales', ['--scheduled' => true]);
                Cache::put('pos-sync:scanner-heartbeat', ['at' => now()->toIso8601String(), 'success' => $code === 0], now()->addMinutes(10));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            if ($this->option('once')) break;
            usleep((int) (max(0.1, 10 - (microtime(true) - $started)) * 1000000));
        } while (true);
        return self::SUCCESS;
    }
}
