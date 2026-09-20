<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SalesPostingControl extends Command
{
    protected $signature = 'sales:posting-control {action=status : status, pause or resume}';
    protected $description = 'Pause or resume new sales receipt postings, including already queued jobs';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (!in_array($action, ['status', 'pause', 'resume'])) {
            $this->error('Use status, pause or resume.');
            return self::FAILURE;
        }
        if ($action !== 'status') Cache::forever('sales-posting:paused', $action === 'pause');
        $paused = config('sales_posting.paused') || Cache::get('sales-posting:paused', false);
        $this->info($paused ? 'Sales posting paused. An in-flight receipt may finish; subsequent receipts will not post.' : 'Sales posting is not paused. Automatic discovery still requires POS_SYNC_ENABLED.');
        return self::SUCCESS;
    }
}
