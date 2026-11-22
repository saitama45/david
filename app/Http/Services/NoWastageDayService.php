<?php

namespace App\Http\Services;

use App\Enums\WastageStatus;
use App\Models\NoWastageDay;
use App\Models\User;
use App\Models\Wastage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "No Wastage for the Day": a store states it had no wastage on a date, so accounting can tell a
 * day with nothing to record from a wastage record not yet made.
 *
 * A day is one or the other, never both: the statement is refused while the store has a wastage
 * record dated that day (any status but cancelled), and a marked day takes no wastage record -
 * recordProblem() refuses it. A day marked by mistake is unmarked (withdrawn_at / withdrawn_by, the
 * row is kept), after which items can be recorded.
 */
class NoWastageDayService
{
    public function __construct(
        private MonthEndClosedPeriodService $closedPeriods,
    ) {}

    /** Why the store cannot state No Wastage for $date; null when it can. */
    public function problem(int $storeBranchId, string $date): ?string
    {
        $date = Carbon::parse($date)->toDateString();

        if ($date > Carbon::now('Asia/Manila')->toDateString()) {
            return 'No Wastage cannot be recorded for a future date.';
        }

        if ($closed = $this->closedPeriods->problem($storeBranchId, $date)) {
            return $closed;
        }

        if ($this->wastageNumbersOn($storeBranchId, $date) !== []) {
            return 'This store already has a wastage record dated ' . Carbon::parse($date)->format('M j, Y')
                . '. A day with wastage cannot be marked No Wastage.';
        }

        if ($this->standing($storeBranchId, $date)) {
            return 'This store is already marked No Wastage for ' . Carbon::parse($date)->format('M j, Y') . '.';
        }

        return null;
    }

    /** Records the statement; the caller has checked problem(). A withdrawn one is stated again. */
    public function declare(int $storeBranchId, string $date, User $user): NoWastageDay
    {
        $date = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($storeBranchId, $date, $user) {
            $day = NoWastageDay::where('store_branch_id', $storeBranchId)
                ->whereDate('wastage_date', $date)
                ->lockForUpdate()
                ->first() ?? new NoWastageDay(['store_branch_id' => $storeBranchId, 'wastage_date' => $date]);

            $day->fill([
                'declared_by' => $user->id,
                'declared_at' => Carbon::now('Asia/Manila'),
                'withdrawn_at' => null,
                'withdrawn_by' => null,
            ])->save();

            return $day;
        });
    }

    /**
     * Why no wastage record may be saved for the store on $date: the day is marked No Wastage.
     * Every way of creating a wastage record must ask it.
     */
    public function recordProblem(int $storeBranchId, ?string $date): ?string
    {
        // An unreadable date is the date rule's to report.
        if (blank($date) || strtotime($date) === false || ! $this->standing($storeBranchId, $date)) {
            return null;
        }

        return 'This store is marked No Wastage for ' . Carbon::parse($date)->format('M j, Y')
            . '. Unmark the day on Create Wastage Record to record wastage.';
    }

    /** Why the store's mark for $date cannot be undone; null when it can. */
    public function unmarkProblem(int $storeBranchId, string $date): ?string
    {
        if (! $this->standing($storeBranchId, $date)) {
            return 'This day is not marked No Wastage.';
        }

        return $this->closedPeriods->problem($storeBranchId, Carbon::parse($date)->toDateString());
    }

    /**
     * Undoes a mark made by mistake; the caller has checked unmarkProblem(). Kept, never deleted:
     * through the model, so the audit trail shows who unmarked it.
     */
    public function unmark(int $storeBranchId, string $date, User $user): void
    {
        DB::transaction(function () use ($storeBranchId, $date, $user) {
            NoWastageDay::where('store_branch_id', $storeBranchId)
                ->whereDate('wastage_date', Carbon::parse($date)->toDateString())
                ->standing()
                ->lockForUpdate()
                ->get()
                ->each(fn (NoWastageDay $day) => $day->update([
                    'withdrawn_at' => Carbon::now('Asia/Manila'),
                    'withdrawn_by' => $user->id,
                ]));
        });
    }

    public function standing(int $storeBranchId, string $date): ?NoWastageDay
    {
        return NoWastageDay::with('declarer:id,first_name,last_name')
            ->where('store_branch_id', $storeBranchId)
            ->whereDate('wastage_date', Carbon::parse($date)->toDateString())
            ->standing()
            ->first();
    }

    /** What the Create page shows under the Wastage Date for a store and date. */
    public function status(int $storeBranchId, string $date): array
    {
        $date = Carbon::parse($date)->toDateString();
        $day = $this->standing($storeBranchId, $date);

        return [
            'declared' => $day ? [
                'by' => trim(($day->declarer?->first_name ?? '') . ' ' . ($day->declarer?->last_name ?? '')) ?: 'Unknown user',
                'at' => $day->declared_at?->format('M j, Y g:i A'),
            ] : null,
            'wastage_numbers' => $this->wastageNumbersOn($storeBranchId, $date),
            'problem' => $day ? null : $this->problem($storeBranchId, $date),
        ];
    }

    /** @return list<string> wastage numbers of the store dated $date, cancelled ones left out */
    private function wastageNumbersOn(int $storeBranchId, string $date): array
    {
        return Wastage::where('store_branch_id', $storeBranchId)
            ->whereDate('wastage_date', $date)
            ->where('wastage_status', '!=', WastageStatus::CANCELLED->value)
            ->distinct()
            ->orderBy('wastage_no')
            ->pluck('wastage_no')
            ->all();
    }
}
