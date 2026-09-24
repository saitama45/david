<?php

namespace App\Http\Services;

use App\Models\OrdersCutoff;
use Carbon\Carbon;

/**
 * Single source of truth for ordering cutoffs on Mass Orders and DTS Mass Orders.
 *
 * The same maths used to live, copy-pasted, in MassOrdersController (dates +
 * edit page), DTSMassOrdersController (dates + edit lock) and the Mass Orders
 * Vue page (edit lock). It was only ever enforced in the browser; the
 * controllers now call this service so the rule is enforced on the server and
 * a business-rule exception can lift it for one approved transaction.
 *
 * Every method takes an optional `$now` so the rules are testable at exact
 * cutoff boundaries. All evaluation is Asia/Manila wall-clock time.
 *
 * cutoff_N_day is 1 = Monday ... 6 = Saturday, 7 = Sunday; days_covered_N is a
 * comma list of Sun/Mon/.../Sat. A cutoff whose day or time is empty is ignored.
 */
class OrderingCutoffService
{
    public const TIMEZONE = 'Asia/Manila';

    /** Mass order template with no delivery-date restriction at all. */
    public const UNRESTRICTED_MASS_TEMPLATE = 'CPO';

    /**
     * Temporary: mass order templates with no cutoff also accept today and this many
     * past days, so late deliveries can still be encoded. Set to 0 to restore tomorrow-only.
     */
    public const MASS_ORDER_BACKDATE_DAYS = 60;

    private const DAY_MAP = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

    /** @var array<string, OrdersCutoff|null> */
    private array $cutoffs = [];

    public function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public function cutoffFor(string $orderingTemplate): ?OrdersCutoff
    {
        if (! array_key_exists($orderingTemplate, $this->cutoffs)) {
            $this->cutoffs[$orderingTemplate] = OrdersCutoff::where('ordering_template', $orderingTemplate)->first();
        }

        return $this->cutoffs[$orderingTemplate];
    }

    // --- Mass orders ---------------------------------------------------------

    /** The orders_cutoff row a mass-order supplier code is configured under. */
    public function massOrderTemplate(string $supplierCode): string
    {
        return $supplierCode === 'DROPS' ? 'FRUITS AND VEGETABLES' : $supplierCode;
    }

    /**
     * Delivery dates a mass order may currently be placed for (Y-m-d).
     * A template with no cutoff configured is open from MASS_ORDER_BACKDATE_DAYS ago
     * up to tomorrow + 59 days.
     */
    public function massOrderAvailableDates(string $supplierCode, ?Carbon $now = null): array
    {
        $now ??= $this->now();
        $cutoff = $this->cutoffFor($this->massOrderTemplate($supplierCode));

        return $cutoff ? $this->massOrderDatesFor($cutoff, $supplierCode, $now)
            : $this->openDates($now, self::MASS_ORDER_BACKDATE_DAYS);
    }

    /** Pure: mass-order dates for a cutoff row at a moment in time. */
    public function massOrderDatesFor(object $cutoff, string $supplierCode, Carbon $now): array
    {
        $now = $now->copy()->timezone(self::TIMEZONE);
        $template = $this->massOrderTemplate($supplierCode);

        // Templates delivered a week later than ordered.
        $shifted = str_starts_with($supplierCode, 'GSI')
            || $supplierCode === 'PUL-O'
            || $supplierCode === 'CPO'
            || $template === 'FRUITS AND VEGETABLES';

        $cutoff1 = $this->cutoffMoment($cutoff->cutoff_1_day, $cutoff->cutoff_1_time, $now);
        $cutoff2 = $this->cutoffMoment($cutoff->cutoff_2_day, $cutoff->cutoff_2_time, $now);

        if ($cutoff1 && $now->lt($cutoff1)) {
            [$days, $weekOffset] = [$cutoff->days_covered_1, $shifted ? 1 : 0];
        } elseif ($cutoff2 && $now->lt($cutoff2)) {
            [$days, $weekOffset] = [$cutoff->days_covered_2, $shifted ? 1 : 0];
        } else {
            [$days, $weekOffset] = [$cutoff->days_covered_1, $shifted ? 2 : 1];
        }

        return $this->datesInWeek($days, $now->copy()->startOfWeek(Carbon::SUNDAY)->addWeeks($weekOffset));
    }

    /**
     * Whether a mass order may be placed for this delivery date right now.
     * Returns null when allowed, otherwise the reason it is blocked.
     */
    public function massOrderDateBlock(string $supplierCode, string $orderDate, ?Carbon $now = null): ?string
    {
        if ($supplierCode === self::UNRESTRICTED_MASS_TEMPLATE) {
            return null;
        }

        $date = Carbon::parse($orderDate)->toDateString();

        if (in_array($date, $this->massOrderAvailableDates($supplierCode, $now), true)) {
            return null;
        }

        return $this->cutoffFor($this->massOrderTemplate($supplierCode))
            ? "The ordering cutoff for {$supplierCode} deliveries on ".Carbon::parse($date)->format('M j, Y').' has passed.'
            : "{$supplierCode} deliveries can only be ordered from ".self::MASS_ORDER_BACKDATE_DAYS.' days back up to 60 days ahead.';
    }

    /**
     * The moment a mass order stops being editable: the first cutoff after it
     * was placed. Null means no cutoff applies (always editable by time).
     */
    public function massOrderEditDeadline(string $supplierCode, $createdAt): ?Carbon
    {
        $cutoff = $this->cutoffFor($supplierCode);

        return $cutoff && $createdAt ? $this->editDeadlineFor($cutoff, Carbon::parse($createdAt, self::TIMEZONE)) : null;
    }

    /** Pure: first cutoff strictly after the placement time. */
    public function editDeadlineFor(object $cutoff, Carbon $placedAt): ?Carbon
    {
        $placedAt = $placedAt->copy()->timezone(self::TIMEZONE)->second(0);
        $rules = [];

        foreach ([[$cutoff->cutoff_1_day, $cutoff->cutoff_1_time], [$cutoff->cutoff_2_day, $cutoff->cutoff_2_time]] as [$day, $time]) {
            if ($day === null || $day === '' || ! $time) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', (string) $time));
            $rules[] = ['day' => ((int) $day) % 7, 'minutes' => $hour * 60 + $minute];
        }

        if (! $rules) {
            return null;
        }

        usort($rules, fn ($a, $b) => [$a['day'], $a['minutes']] <=> [$b['day'], $b['minutes']]);

        $placedDay = $placedAt->dayOfWeek;
        $placedMinutes = $placedAt->hour * 60 + $placedAt->minute;

        $next = collect($rules)->first(fn ($rule) => $rule['day'] > $placedDay
            || ($rule['day'] === $placedDay && $rule['minutes'] > $placedMinutes));

        if ($next) {
            $daysToAdd = ($next['day'] - $placedDay + 7) % 7;
        } else {
            $next = $rules[0];
            $daysToAdd = (7 - $placedDay) + $next['day'];
        }

        return $placedAt->copy()->startOfDay()->addDays($daysToAdd)->addMinutes($next['minutes']);
    }

    /** Statuses in which a mass order may still be edited. Not waivable. */
    public function massOrderEditableStatuses(string $supplierCode): array
    {
        return $supplierCode === 'DROPS' ? ['pending', 'approved', 'committed'] : ['pending', 'approved'];
    }

    // --- DTS mass orders -----------------------------------------------------

    /**
     * Delivery dates a DTS mass order may currently be placed for, before
     * already-booked dates are removed. With no cutoff row: tomorrow + 59 days.
     */
    public function dtsEnabledDates(string $variant, ?Carbon $now = null): array
    {
        $now ??= $this->now();
        $cutoff = $this->cutoffFor($variant);

        return $cutoff ? $this->dtsDatesFor($cutoff, $now) : $this->openDates($now);
    }

    /** Pure: DTS dates for a cutoff row at a moment in time. */
    public function dtsDatesFor(object $cutoff, Carbon $now): array
    {
        $now = $now->copy()->timezone(self::TIMEZONE);
        $cutoff1 = $this->cutoffMoment($cutoff->cutoff_1_day, $cutoff->cutoff_1_time, $now);
        $cutoff2 = $this->cutoffMoment($cutoff->cutoff_2_day, $cutoff->cutoff_2_time, $now);

        if ($cutoff1 && $now->lt($cutoff1)) {
            // A single weekly cutoff orders for next week; a split cutoff for this week.
            [$days, $weekOffset] = [$cutoff->days_covered_1, $cutoff->cutoff_2_day ? 0 : 1];
        } elseif ($cutoff2 && $now->lt($cutoff2)) {
            [$days, $weekOffset] = [$cutoff->days_covered_2, 0];
        } else {
            [$days, $weekOffset] = [$cutoff->days_covered_1, 1];
        }

        return $this->datesInWeek($days, $now->copy()->startOfWeek(Carbon::SUNDAY)->addWeeks($weekOffset));
    }

    /**
     * Pure: whether a DTS batch is past its edit cutoff. `$dateFrom` is the
     * batch's earliest delivery date. Null cutoff means never time-locked.
     */
    public function dtsBatchEditLockedFor(?object $cutoff, string $dateFrom, Carbon $now): bool
    {
        if (! $cutoff) {
            return false;
        }

        $now = $now->copy()->timezone(self::TIMEZONE);
        $from = Carbon::parse($dateFrom, self::TIMEZONE)->startOfDay();
        $batchWeek = $from->copy()->startOfWeek(Carbon::SUNDAY);
        $currentWeek = $now->copy()->startOfWeek(Carbon::SUNDAY);

        if ($batchWeek->lt($currentWeek)) {
            return true;
        }

        if (! $batchWeek->eq($currentWeek)) {
            return false;
        }

        $cutoff1 = $this->cutoffMoment($cutoff->cutoff_1_day, $cutoff->cutoff_1_time, $now);
        $cutoff2 = $this->cutoffMoment($cutoff->cutoff_2_day, $cutoff->cutoff_2_time, $now);

        if ($cutoff2 && $now->gte($cutoff2)) {
            return true;
        }

        return $cutoff1 && $now->gte($cutoff1)
            && in_array($from->toDateString(), $this->datesInWeek($cutoff->days_covered_1, $batchWeek), true);
    }

    public function dtsBatchEditLocked(string $variant, string $dateFrom, ?Carbon $now = null): bool
    {
        return $this->dtsBatchEditLockedFor($this->cutoffFor($variant), $dateFrom, $now ?? $this->now());
    }

    // --- helpers -------------------------------------------------------------

    /** Dates open to a template with no cutoff row: tomorrow + 59 days, plus today and $daysBack past days. */
    private function openDates(Carbon $now, int $daysBack = 0): array
    {
        $dates = [];
        $tomorrow = $now->copy()->timezone(self::TIMEZONE)->addDay()->startOfDay();
        $end = $tomorrow->copy()->addDays(59);
        $date = $daysBack > 0 ? $tomorrow->copy()->subDays($daysBack + 1) : $tomorrow;
        for (; $date->lte($end); $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    private function cutoffMoment($day, $time, Carbon $now): ?Carbon
    {
        if (! $day || ! $time) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', (string) $time));

        return $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays(((int) $day) % 7)->setTime($hour, $minute);
    }

    private function datesInWeek(?string $days, Carbon $weekStart): array
    {
        $dates = [];

        foreach ($days ? explode(',', $days) : [] as $day) {
            $day = trim($day);

            if (isset(self::DAY_MAP[$day])) {
                $dates[] = $weekStart->copy()->addDays(self::DAY_MAP[$day])->toDateString();
            }
        }

        return $dates;
    }
}
