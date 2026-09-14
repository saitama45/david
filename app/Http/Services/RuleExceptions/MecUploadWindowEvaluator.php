<?php

namespace App\Http\Services\RuleExceptions;

use App\Http\Services\MonthEndCountSettingsService;
use App\Http\Services\OrderingCutoffService;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndCountReopen;
use App\Models\MonthEndSchedule;
use App\Models\User;
use Carbon\Carbon;

/** mec.upload_window - upload a Month End Count after its upload window closed. */
class MecUploadWindowEvaluator extends BaseEvaluator
{
    public function __construct(OrderingCutoffService $cutoffs, private MonthEndCountSettingsService $settings)
    {
        parent::__construct($cutoffs);
    }

    public static function subjectKey(int $scheduleId, int $branchId): string
    {
        return $scheduleId.'|'.$branchId;
    }

    public function inputRules(): array
    {
        return [
            'schedule_id' => ['required', 'integer'],
            'store_branch_id' => ['required', 'integer'],
        ];
    }

    public function resolve(array $input, User $user): RuleSubject
    {
        $schedule = MonthEndSchedule::query()->find((int) $input['schedule_id']);

        if (! $schedule) {
            $this->deny('That month end count schedule was not found.');
        }

        if ($schedule->status === 'expired') {
            $this->deny('This schedule has expired and is closed for uploads. An exception cannot reopen it.');
        }

        $store = $this->activeStore((int) $input['store_branch_id']);

        $submitted = MonthEndCountItem::query()
            ->where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $store->id)
            ->whereNotIn('status', ['rejected'])
            ->exists();

        if ($submitted) {
            $this->deny("{$store->name} has already uploaded for this count.");
        }

        return new RuleSubject((int) $store->id, self::subjectKey((int) $schedule->id, (int) $store->id), [
            'schedule_id' => (int) $schedule->id,
            'calculated_date' => Carbon::parse($schedule->calculated_date)->toDateString(),
            'store_name' => $store->name,
            'summary' => 'Month end count of '.Carbon::parse($schedule->calculated_date)->format('M j, Y'),
        ]);
    }

    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string
    {
        $settings = $this->settings->current();
        $countDate = Carbon::parse($subject->context['calculated_date'], OrderingCutoffService::TIMEZONE);

        if ($now->lt($this->settings->uploadStart($countDate, $settings))) {
            return 'The upload window has not opened yet, so there is nothing to except.';
        }

        if ($this->settings->isUploadOpen($now, $countDate, $settings)) {
            return 'The upload window is still open, so no exception is needed.';
        }

        $reopened = MonthEndCountReopen::query()
            ->where('month_end_schedule_id', $subject->context['schedule_id'])
            ->where('branch_id', $subject->storeBranchId)
            ->get()
            ->contains(fn ($reopen) => $now->lte(Carbon::parse($reopen->reopened_until->format('Y-m-d H:i:s'), OrderingCutoffService::TIMEZONE)));

        return $reopened ? 'Support has already reopened the upload for this store.' : null;
    }
}
