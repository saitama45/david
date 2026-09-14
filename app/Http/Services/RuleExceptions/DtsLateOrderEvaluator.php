<?php

namespace App\Http\Services\RuleExceptions;

use App\Http\Services\OrderingCutoffService;
use App\Models\StoreOrder;
use App\Models\User;
use Carbon\Carbon;

/** dts_mass_order.late_order - place a DTS mass order for a date whose cutoff has passed. */
class DtsLateOrderEvaluator extends BaseEvaluator
{
    public static function subjectKey(string $variant, string $date, int $storeBranchId): string
    {
        return implode('|', [$variant, Carbon::parse($date)->toDateString(), $storeBranchId]);
    }

    /** A date already taken by another batch of this variant. Never waivable. */
    public static function isBooked(string $variant, string $date): bool
    {
        return StoreOrder::query()
            ->whereNotNull('batch_reference')
            ->where('variant', 'mass dts')
            ->where('remarks', 'LIKE', "Mass DTS Order - {$variant}")
            ->whereDate('order_date', $date)
            ->whereHas('store_order_items')
            ->toBase()
            ->exists();
    }

    /**
     * The delivery-date span a DTS batch may currently cover, mirroring the
     * Create screen: the batch's From and To must each be an available date
     * (enabled by the cutoff and not already booked), and every date between
     * them is then orderable. Null when no date is available.
     *
     * @return array{0:string,1:string}|null
     */
    public static function orderableSpan(OrderingCutoffService $cutoffs, string $variant, ?Carbon $now = null): ?array
    {
        $booked = StoreOrder::query()
            ->whereNotNull('batch_reference')
            ->where('variant', 'mass dts')
            ->where('remarks', 'LIKE', "Mass DTS Order - {$variant}")
            ->whereHas('store_order_items')
            ->toBase()
            ->distinct()
            ->pluck('order_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $available = array_values(array_diff($cutoffs->dtsEnabledDates($variant, $now), $booked));
        sort($available);

        return $available ? [$available[0], end($available)] : null;
    }

    public static function inSpan(?array $span, string $date): bool
    {
        return $span !== null && $date >= $span[0] && $date <= $span[1];
    }

    public function inputRules(): array
    {
        return [
            'variant' => ['required', 'string', 'max:64'],
            'order_date' => ['required', 'date'],
            'store_branch_id' => ['required', 'integer'],
        ];
    }

    public function resolve(array $input, User $user): RuleSubject
    {
        $variant = (string) $input['variant'];
        $date = $this->futureDeliveryDate($input['order_date'], $this->cutoffs->now());
        $store = $this->activeStore((int) $input['store_branch_id']);

        if (! $this->cutoffs->cutoffFor($variant)) {
            $this->deny("No ordering cutoff is configured for {$variant}. Ask for the cutoff to be set up instead.");
        }

        if (! $this->onDeliverySchedule($store, $variant, $date)) {
            $this->deny("{$store->name} is not on the {$variant} delivery schedule for ".Carbon::parse($date)->format('l').'.');
        }

        if (self::isBooked($variant, $date)) {
            $this->deny("A {$variant} batch already exists for ".Carbon::parse($date)->format('M j, Y').'. Edit that batch instead.');
        }

        return new RuleSubject((int) $store->id, self::subjectKey($variant, $date, (int) $store->id), [
            'variant' => $variant,
            'order_date' => $date,
            'store_name' => $store->name,
            'summary' => "{$variant} delivery on ".Carbon::parse($date)->format('M j, Y'),
        ]);
    }

    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string
    {
        $open = self::inSpan(self::orderableSpan($this->cutoffs, $subject->context['variant'], $now), $subject->context['order_date']);

        return $open ? 'This delivery date is still open for ordering, so no exception is needed.' : null;
    }

    public function latestValidUntil(RuleSubject $subject, Carbon $now): ?Carbon
    {
        return $this->startOfDate($subject->context['order_date']);
    }
}
