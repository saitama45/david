<?php

namespace App\Http\Services\RuleExceptions;

use App\Models\User;
use Carbon\Carbon;

/** mass_order.late_order - place a mass order for a delivery date whose cutoff has passed. */
class MassOrderLateOrderEvaluator extends BaseEvaluator
{
    public static function subjectKey(string $supplierCode, string $date, int $storeBranchId): string
    {
        return implode('|', [$supplierCode, Carbon::parse($date)->toDateString(), $storeBranchId]);
    }

    public function inputRules(): array
    {
        return [
            'supplier_code' => ['required', 'string', 'exists:suppliers,supplier_code'],
            'order_date' => ['required', 'date'],
            'store_branch_id' => ['required', 'integer'],
        ];
    }

    public function resolve(array $input, User $user): RuleSubject
    {
        $supplierCode = (string) $input['supplier_code'];
        $date = $this->futureDeliveryDate($input['order_date'], $this->cutoffs->now());
        $store = $this->activeStore((int) $input['store_branch_id']);

        if (! $user->suppliers()->where('suppliers.supplier_code', $supplierCode)->exists()) {
            $this->deny('You do not have access to the selected ordering template.');
        }

        if (! $this->cutoffs->cutoffFor($this->cutoffs->massOrderTemplate($supplierCode))) {
            $this->deny("No ordering cutoff is configured for {$supplierCode}. Ask for the cutoff to be set up instead.");
        }

        if (! $this->onDeliverySchedule($store, $supplierCode, $date)) {
            $this->deny("{$store->name} is not on the {$supplierCode} delivery schedule for ".Carbon::parse($date)->format('l').'.');
        }

        return new RuleSubject((int) $store->id, self::subjectKey($supplierCode, $date, (int) $store->id), [
            'supplier_code' => $supplierCode,
            'order_date' => $date,
            'store_name' => $store->name,
            'summary' => "{$supplierCode} delivery on ".Carbon::parse($date)->format('M j, Y'),
        ]);
    }

    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string
    {
        $blocked = $this->cutoffs->massOrderDateBlock($subject->context['supplier_code'], $subject->context['order_date'], $now);

        return $blocked ? null : 'This delivery date is still open for ordering, so no exception is needed.';
    }

    /** The grant must be used before the delivery day starts. */
    public function latestValidUntil(RuleSubject $subject, Carbon $now): ?Carbon
    {
        return $this->startOfDate($subject->context['order_date']);
    }
}
