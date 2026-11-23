<?php

namespace App\Jobs;

use App\Enums\WastageStatus;
use App\Http\Services\NotificationMailService;
use App\Models\Scopes\EntityScope;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Wastage;
use App\Support\EntityContext;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Emails the store's Wastage Approval Email (Store Branches) a wastage that got its final approval.
 * Dispatched after the response by `WastageService::finalizeWastageApproval()`, so the approver
 * does not wait for the mail server and no queue worker is needed; a mail failure is only logged.
 */
class SendWastageApprovedEmail
{
    use Dispatchable;

    /**
     * @param  list<int>  $lineIds  the wastage rows approved together (one wastage number)
     */
    public function __construct(public array $lineIds, public int $storeBranchId, public ?int $approvedById = null) {}

    public function handle(NotificationMailService $mail, EntityContext $context): void
    {
        $branch = StoreBranch::withoutGlobalScope(EntityScope::class)->find($this->storeBranchId);
        if (! $branch) {
            return;
        }

        $context->runAs($branch->entity_id, function () use ($mail, $branch) {
            // Read again: only what is still approved (an approval rolled back sends nothing).
            $lines = Wastage::whereIn('id', $this->lineIds)
                ->where('store_branch_id', $branch->id)
                ->where('wastage_status', WastageStatus::APPROVED_LVL2->value)
                ->with(['sapMasterfile', 'posMasterfile', 'encoder', 'approver1'])
                ->orderBy('id')
                ->get();

            $mail->sendWastageApproved($branch, $lines, $this->approvedById ? User::find($this->approvedById) : null);
        });
    }
}
