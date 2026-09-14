<?php

namespace App\Http\Services;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard > Go-Live Stores tab.
 *
 * Tracks the rollout of DAVID across the stores listed in /branches. A store
 * goes live in the ISO week of its first ordering transaction - its first
 * /mass-orders order (store_orders.variant = 'mass regular', any status) - and
 * stays live. Whether it keeps ordering afterwards is the Success Rate and
 * Adoption Rate tabs' concern, not this one.
 *
 * The store universe is every active branch of the active entity, independent of
 * the viewer's own store assignments. Inactive branches (Head Office, test and
 * dropship placeholders) are not stores that can go live.
 */
class GoLiveStoresService
{
    public const ORDERING_VARIANT = 'mass regular';

    public const START_WEEK = 19;

    /**
     * @param  array{date_from?:string|null,date_to?:string|null}  $filters
     */
    public function getWeeklyTrend(array $filters): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);

        $stores = StoreBranch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_code']);

        $weeks = $this->buildWeekBuckets($dateFrom, $dateTo);
        $goLiveDates = $this->goLiveDates($stores->pluck('id')->all());
        $rows = $this->buildRows($weeks, $stores, $goLiveDates);

        return [
            'rows' => $rows,
            'totals' => $this->buildTotals($rows, $stores, $goLiveDates),
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
        ];
    }

    /**
     * Pure: one row per week from the store list and each store's go-live date.
     *
     * @param  Collection<int, object{id:int,name:string}>  $stores
     * @param  array<int, string>  $goLiveDates  store id => Y-m-d of its first ordering transaction
     */
    private function buildRows(array $weeks, Collection $stores, array $goLiveDates): array
    {
        $totalStores = $stores->count();
        $names = $stores->mapWithKeys(fn ($store) => [(int) $store->id => $store->name]);

        return collect($weeks)->map(function (array $week) use ($totalStores, $names, $goLiveDates) {
            $newStores = [];
            $liveCount = 0;

            foreach ($goLiveDates as $storeId => $date) {
                if ($date > $week['end_date']) {
                    continue;
                }

                $liveCount++;

                if ($date >= $week['start_date']) {
                    $newStores[] = $names[$storeId] ?? (string) $storeId;
                }
            }

            sort($newStores);

            return [
                'week_start' => $week['start_date'],
                'week_end' => $week['end_date'],
                'week_label' => 'Week '.$week['week_no'],
                'week_range' => $week['label'],
                'new_go_live' => count($newStores),
                'new_stores' => $newStores,
                'live_stores' => $liveCount,
                'not_live_stores' => max($totalStores - $liveCount, 0),
                'total_stores' => $totalStores,
                'go_live_rate' => $totalStores > 0 ? round($liveCount / $totalStores * 100, 2) : null,
            ];
        })->values()->all();
    }

    private function buildTotals(array $rows, Collection $stores, array $goLiveDates): array
    {
        $last = end($rows) ?: [];

        $notLive = $stores
            ->reject(fn ($store) => isset($goLiveDates[(int) $store->id]))
            ->map(fn ($store) => $store->name)
            ->sort()
            ->values()
            ->all();

        return [
            'total_stores' => $stores->count(),
            'live_stores' => $last['live_stores'] ?? 0,
            'go_live_rate' => $last['go_live_rate'] ?? null,
            'new_in_range' => array_sum(array_column($rows, 'new_go_live')),
            'not_live_stores' => $notLive,
            // Every live store with the day it went live, so the UI can list the
            // stores behind any week's count (go_live_date <= that week's end).
            'live_store_list' => $stores
                ->filter(fn ($store) => isset($goLiveDates[(int) $store->id]))
                ->map(fn ($store) => [
                    'id' => (int) $store->id,
                    'name' => $store->name,
                    'branch_code' => $store->branch_code ?? null,
                    'go_live_date' => $goLiveDates[(int) $store->id],
                    'go_live_week' => 'Week '.Carbon::parse($goLiveDates[(int) $store->id])->isoWeek,
                ])
                ->sortBy([['go_live_date', 'asc'], ['name', 'asc']])
                ->values()
                ->all(),
            'weeks' => count($rows),
        ];
    }

    /**
     * Each store's first-ever ordering transaction, regardless of the selected
     * range, so a store that went live before the range is already live in it.
     *
     * @return array<int, string> store id => Y-m-d
     */
    private function goLiveDates(array $storeIds): array
    {
        if (empty($storeIds)) {
            return [];
        }

        // toBase() keeps EntityScope but skips StoreOrder's default eager loads.
        return StoreOrder::query()
            ->where('variant', self::ORDERING_VARIANT)
            ->whereIn('store_branch_id', $storeIds)
            ->selectRaw('store_branch_id, MIN(created_at) AS first_order_at')
            ->groupBy('store_branch_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->store_branch_id => Carbon::parse($row->first_order_at)->toDateString(),
            ])
            ->all();
    }

    /** Defaults to ISO week 19 of the latest year that has reached it, through today. */
    private function resolveDateRange(array $filters): array
    {
        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])
            : Carbon::today();

        if (! empty($filters['date_from'])) {
            $dateFrom = Carbon::parse($filters['date_from']);
        } else {
            $year = Carbon::today()->isoWeekYear;
            $dateFrom = Carbon::today()->setISODate($year, self::START_WEEK)->startOfWeek(Carbon::MONDAY);

            if ($dateFrom->gt($dateTo)) {
                $dateFrom = Carbon::today()->setISODate($year - 1, self::START_WEEK)->startOfWeek(Carbon::MONDAY);
            }
        }

        if ($dateFrom->gt($dateTo)) {
            $dateFrom = $dateTo->copy()->startOfWeek(Carbon::MONDAY);
        }

        return [$dateFrom->startOfDay(), $dateTo->endOfDay()];
    }

    private function buildWeekBuckets(Carbon $dateFrom, Carbon $dateTo): array
    {
        $weeks = [];

        for (
            $weekStart = $dateFrom->copy()->startOfWeek(Carbon::MONDAY);
            $weekStart->lte($dateTo);
            $weekStart->addWeek()
        ) {
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

            $weeks[] = [
                'start_date' => $weekStart->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'week_no' => (int) $weekStart->isoWeek,
                'label' => $weekStart->format('M j').'-'.$weekEnd->format('M j'),
            ];
        }

        return $weeks;
    }
}
