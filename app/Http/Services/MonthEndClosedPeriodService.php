<?php

namespace App\Http\Services;

use App\Services\MonthEndStockVariance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dates a store can no longer date a transaction on.
 *
 * Once a store's month end count has its final (Level 2) approval, the period that count
 * covers is settled: its variance is reported and its stock was set to the count. A
 * transaction dated inside it would change a month already signed off, so the create and
 * edit forms of ordering, receiving, wastage and sales take no date on or before the
 * count's last day - in the date picker, and again on the server.
 *
 * "Closed through" is the last day of the period the count is read over
 * (MonthEndStockVariance::periodFor()): its MEC Scheduled Date, kept inside the month
 * counted. A count dated Oct 29 closes through Oct 29, so the store still records Oct 30
 * and 31. It is per store: a store whose count is not final approved stays open.
 */
class MonthEndClosedPeriodService
{
    /** @var array<int, string|null> branch id => Y-m-d, null when the store has no final approved count */
    private array $through = [];

    /**
     * The last closed date of each store that has a final approved count.
     *
     * @return array<int, string> branch id => Y-m-d; a store with nothing closed is left out
     */
    public function closedThrough($branchIds): array
    {
        $branchIds = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        // SQL Server allows 2100 parameters per query.
        foreach (array_chunk(array_diff($branchIds, array_keys($this->through)), 1000) as $chunk) {
            $this->through += array_fill_keys($chunk, null);

            // Read by branch, not through the entity-scoped models: a branch belongs to one
            // entity already, and older schedules carry no entity.
            $counts = DB::table('month_end_count_items as meci')
                ->join('month_end_schedules as mes', 'mes.id', '=', 'meci.month_end_schedule_id')
                ->whereIn('meci.branch_id', $chunk)
                ->where('meci.status', 'level2_approved')
                ->select('meci.branch_id', 'mes.year', 'mes.month', 'mes.calculated_date')
                ->distinct()
                ->get();

            foreach ($counts as $count) {
                [, $through] = MonthEndStockVariance::periodFor((int) $count->year, (int) $count->month, $count->calculated_date);
                $this->through[(int) $count->branch_id] = max($through, $this->through[(int) $count->branch_id] ?? '');
            }
        }

        return array_filter(array_intersect_key($this->through, array_flip($branchIds)));
    }

    public function closedThroughFor(int $branchId): ?string
    {
        return $this->closedThrough([$branchId])[$branchId] ?? null;
    }

    /**
     * For a date picker several stores share: the last date closed for every one of them,
     * or null while any of them has nothing closed. A later date is still open to at least
     * one store, so it stays selectable and the closed stores are refused one by one.
     */
    public function closedForAll($branchIds): ?string
    {
        $branchIds = collect($branchIds)->map(fn ($id) => (int) $id)->unique();
        $closed = $this->closedThrough($branchIds);

        return $branchIds->isNotEmpty() && count($closed) === $branchIds->count() ? min($closed) : null;
    }

    /**
     * Why a transaction of this store cannot be dated $date, or null when it can.
     */
    public function problem(int $branchId, $date): ?string
    {
        $through = $this->closedThroughFor($branchId);

        try {
            $day = $through ? Carbon::parse($date)->toDateString() : null;
        } catch (\Throwable) {
            // Not a date: the field's own validation says so.
            return null;
        }

        if (! $day || $day > $through) {
            return null;
        }

        $store = DB::table('store_branches')->where('id', $branchId)->value('name') ?? 'this store';

        return "The month end count of {$store} has its final approval, so dates on or before "
            .Carbon::parse($through)->format('M j, Y').' are closed for it. Choose a later date.';
    }
}
