<?php

namespace App\Http\Services;

use App\Models\MonthEndCountItem;
use App\Models\MonthEndCountRejection;
use App\Models\MonthEndCountReopen;
use App\Models\MonthEndSchedule;
use App\Models\StoreBranch;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonthEndCountRejectionService
{
    /** The status a count holds while it waits for each approval level. */
    private const AWAITING = [
        1 => 'pending_level1_approval',
        2 => 'level1_approved',
    ];

    /**
     * Send a branch's count back to the store from the given approval level.
     *
     * Rejecting only means something if the store can correct it, so this also reopens the
     * upload for that branch until the deadline, even when the normal window has closed. The
     * re-upload replaces the rejected rows and starts again at Level 1, whichever level
     * returned it.
     *
     * @return Carbon the re-upload deadline in force (a later reopen already granted wins)
     *
     * @throws DomainException with the message to show the approver when nothing may be rejected
     */
    public function returnToStore(MonthEndSchedule $schedule, StoreBranch $branch, int $level, string $reason, string $reuploadUntil, int $userId): Carbon
    {
        $until = Carbon::parse($reuploadUntil, 'Asia/Manila');

        if ($until->lte(Carbon::now('Asia/Manila'))) {
            throw new DomainException('The re-upload deadline must be in the future.');
        }

        return DB::transaction(function () use ($schedule, $branch, $level, $reason, $until, $userId) {
            $rejectedCount = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->where('branch_id', $branch->id)
                ->where('status', self::AWAITING[$level])
                ->update(['status' => 'rejected']);

            if ($rejectedCount === 0) {
                throw new DomainException("No items found awaiting Level {$level} approval.");
            }

            MonthEndCountRejection::create([
                'entity_id' => $schedule->entity_id,
                'month_end_schedule_id' => $schedule->id,
                'branch_id' => $branch->id,
                'reason' => $reason,
                'item_count' => $rejectedCount,
                'level' => $level,
                'rejected_by' => $userId,
            ]);

            // Never shorten a later reopen support already granted this branch.
            $existing = MonthEndCountReopen::where('month_end_schedule_id', $schedule->id)
                ->where('branch_id', $branch->id)
                ->first();
            $existingUntil = $existing
                ? Carbon::parse($existing->reopened_until->format('Y-m-d H:i:s'), 'Asia/Manila')
                : null;

            if ($existingUntil && ! $existingUntil->lt($until)) {
                return $existingUntil;
            }

            MonthEndCountReopen::updateOrCreate(
                ['month_end_schedule_id' => $schedule->id, 'branch_id' => $branch->id],
                [
                    'entity_id' => $schedule->entity_id,
                    'reopened_until' => $until->format('Y-m-d H:i:s'),
                    'reopened_by' => $userId,
                ]
            );

            return $until;
        });
    }
}
