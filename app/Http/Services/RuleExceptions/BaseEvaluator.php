<?php

namespace App\Http\Services\RuleExceptions;

use App\Http\Services\OrderingCutoffService;
use App\Models\StoreBranch;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

abstract class BaseEvaluator implements RuleEvaluator
{
    public function __construct(protected OrderingCutoffService $cutoffs) {}

    public function latestValidUntil(RuleSubject $subject, Carbon $now): ?Carbon
    {
        return null;
    }

    protected function deny(string $message): never
    {
        throw ValidationException::withMessages(['subject' => $message]);
    }

    /** The store exists in the active entity and is active. Never waivable. */
    protected function activeStore(int $storeBranchId): StoreBranch
    {
        $store = StoreBranch::query()->find($storeBranchId);

        if (! $store || (int) $store->is_active !== 1) {
            $this->deny('The selected store is not an active store.');
        }

        return $store;
    }

    /** The store is scheduled to receive this template/variant on that weekday. Never waivable. */
    protected function onDeliverySchedule(StoreBranch $store, string $variant, string $date): bool
    {
        return $store->delivery_schedules()
            ->wherePivot('variant', $variant)
            ->where('delivery_schedules.day', strtoupper(Carbon::parse($date)->format('l')))
            ->exists();
    }

    /** A delivery date that is still ahead, in Manila time. Never waivable. */
    protected function futureDeliveryDate(string $value, Carbon $now): string
    {
        $date = Carbon::parse($value, OrderingCutoffService::TIMEZONE)->startOfDay();

        if ($date->lte($now->copy()->startOfDay())) {
            $this->deny('An exception can only be requested for a delivery date after today.');
        }

        return $date->toDateString();
    }

    protected function startOfDate(string $date): Carbon
    {
        return Carbon::parse($date, OrderingCutoffService::TIMEZONE)->startOfDay();
    }
}
