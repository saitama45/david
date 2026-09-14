<?php

namespace App\Http\Services\RuleExceptions;

use App\Models\StoreOrder;
use App\Models\User;
use Carbon\Carbon;

/** dts_mass_order.edit_locked - edit a DTS mass order batch once its cutoff has passed. */
class DtsEditEvaluator extends BaseEvaluator
{
    public const VARIANT_PREFIX = 'Mass DTS Order - ';

    /**
     * What an existing batch actually is, read from the database - never from
     * the edit form, which a client can alter.
     *
     * @return array{variant:string,date_from:string,date_to:string,store_ids:int[],statuses:string[]}|null
     */
    public static function batchFacts(string $batchNumber): ?array
    {
        $orders = StoreOrder::query()
            ->where('batch_reference', $batchNumber)
            ->where('variant', 'mass dts')
            ->toBase()
            ->get(['store_branch_id', 'order_date', 'order_status', 'remarks']);

        if ($orders->isEmpty()) {
            return null;
        }

        $remarks = (string) $orders->first()->remarks;

        return [
            'variant' => str_starts_with($remarks, self::VARIANT_PREFIX) ? substr($remarks, strlen(self::VARIANT_PREFIX)) : 'N/A',
            'date_from' => Carbon::parse($orders->min('order_date'))->toDateString(),
            'date_to' => Carbon::parse($orders->max('order_date'))->toDateString(),
            'store_ids' => $orders->pluck('store_branch_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
            'statuses' => $orders->pluck('order_status')->unique()->values()->all(),
        ];
    }

    public function inputRules(): array
    {
        return ['batch_number' => ['required', 'string', 'max:64']];
    }

    public function resolve(array $input, User $user): RuleSubject
    {
        $batchNumber = (string) $input['batch_number'];
        $facts = self::batchFacts($batchNumber);

        if (! $facts) {
            $this->deny('That DTS batch was not found.');
        }

        if ($facts['variant'] === 'N/A') {
            $this->deny('This batch has no recognised variant and cannot be edited.');
        }

        $this->futureDeliveryDate($facts['date_from'], $this->cutoffs->now());

        // Saving an edit deletes and recreates every order in the batch, so a
        // batch with received deliveries must never be reopened.
        if (array_diff($facts['statuses'], ['committed']) !== []) {
            $this->deny('Part of this batch has already moved past commitment (e.g. received), so it cannot be edited. An exception cannot change that.');
        }

        foreach ($facts['store_ids'] as $storeId) {
            $this->activeStore($storeId);
        }

        return new RuleSubject($facts['store_ids'][0], $batchNumber, [
            'batch_number' => $batchNumber,
            'variant' => $facts['variant'],
            'date_from' => $facts['date_from'],
            'date_to' => $facts['date_to'],
            'summary' => "Edit DTS batch {$batchNumber}",
        ], $facts['store_ids']);
    }

    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string
    {
        $locked = $this->cutoffs->dtsBatchEditLocked($subject->context['variant'], $subject->context['date_from'], $now);

        return $locked ? null : 'This batch can still be edited, so no exception is needed.';
    }

    /** A batch cannot be reopened once its first delivery day has started. */
    public function latestValidUntil(RuleSubject $subject, Carbon $now): ?Carbon
    {
        return $this->startOfDate($subject->context['date_from']);
    }
}
