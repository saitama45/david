<?php

namespace App\Console\Commands;

use App\Http\Services\RuleExceptionService;
use Illuminate\Console\Command;

/**
 * Marks approved business-rule exception grants that were never used before
 * their validity ended as expired, logging each one. Scheduled every 15 minutes.
 * Consumption already refuses an out-of-date grant, so this keeps the queue and
 * audit log truthful rather than being the control itself.
 */
class ExpireRuleExceptions extends Command
{
    protected $signature = 'rule-exceptions:expire';

    protected $description = 'Expire approved business-rule exception grants whose validity has passed';

    public function handle(RuleExceptionService $service): int
    {
        $count = $service->expireStale();
        $this->info("Expired {$count} rule exception grant(s).");

        return self::SUCCESS;
    }
}
