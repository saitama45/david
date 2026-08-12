<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('imports:requeue-stuck --apply')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20);

// Consume the 'imports' queue from the scheduler so environments without a
// long-lived worker (IIS / shared hosting, where startup.sh never runs) still
// process uploads. --once matches the one-import-at-a-time design in
// ImportQueueService: each run takes a single job, and SAPMasterfileImportJob /
// StoreTransactionImportJob chain the next pending import when they finish.
// Safe to run alongside the persistent worker in startup.sh — dispatchNextPending()
// keeps at most one imports job in flight, so the extra worker just idles.
Schedule::command('queue:work database --queue=imports --once --tries=1 --timeout=3600')
    ->everyMinute()
    ->withoutOverlapping(70)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

// Clear orphaned 'processing' rows and dispatch the next pending import, so a
// crashed worker cannot leave the queue blocked indefinitely.
Schedule::command('imports:reconcile --apply')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/import-reconciler.log'));
