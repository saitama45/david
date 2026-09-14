<?php

namespace App\Http\Services\RuleExceptions;

use App\Models\StoreOrder;
use App\Models\User;
use Carbon\Carbon;

/** mass_order.edit_after_cutoff - edit a mass order once its edit cutoff has passed. */
class MassOrderEditEvaluator extends BaseEvaluator
{
    public function inputRules(): array
    {
        return ['order_number' => ['required', 'string', 'max:64']];
    }

    public function resolve(array $input, User $user): RuleSubject
    {
        $order = StoreOrder::query()
            ->with('supplier:id,supplier_code')
            ->where('order_number', $input['order_number'])
            ->where('variant', 'mass regular')
            ->first();

        if (! $order) {
            $this->deny('That mass order was not found.');
        }

        $store = $this->activeStore((int) $order->store_branch_id);
        $supplierCode = (string) $order->supplier?->supplier_code;
        $this->futureDeliveryDate(Carbon::parse($order->order_date)->toDateString(), $this->cutoffs->now());

        if (! in_array($order->order_status, $this->cutoffs->massOrderEditableStatuses($supplierCode), true)) {
            $this->deny("This order is already {$order->order_status} and can no longer be edited. An exception cannot change that.");
        }

        return new RuleSubject((int) $store->id, (string) $order->order_number, [
            'order_number' => $order->order_number,
            'order_id' => $order->id,
            'supplier_code' => $supplierCode,
            'order_date' => Carbon::parse($order->order_date)->toDateString(),
            'created_at' => Carbon::parse($order->created_at)->format('Y-m-d H:i:s'),
            'store_name' => $store->name,
            'summary' => "Edit {$order->order_number}",
        ]);
    }

    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string
    {
        $deadline = $this->cutoffs->massOrderEditDeadline($subject->context['supplier_code'], $subject->context['created_at']);

        return $deadline && $now->gte($deadline) ? null : 'This order can still be edited, so no exception is needed.';
    }

    /** An order cannot be edited once its delivery day has started. */
    public function latestValidUntil(RuleSubject $subject, Carbon $now): ?Carbon
    {
        return $this->startOfDate($subject->context['order_date']);
    }
}
