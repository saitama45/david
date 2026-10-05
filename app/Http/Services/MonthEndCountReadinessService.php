<?php

namespace App\Http\Services;

use App\Models\MonthEndSchedule;
use App\Services\MonthEndStockVariance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * What still has to be finished before a store's Month End Count template can carry a
 * trustworthy Current SOH.
 *
 * Current SOH is the Inventory Movement Report's Theoretical SOH over the period of the
 * count (countPeriod()). That figure only counts approved receipts, level 2 approved wastage and the previous
 * month's count, so any order, transfer, wastage or count still open in the period would
 * leave it wrong. The template is withheld until the store's period is settled.
 */
class MonthEndCountReadinessService
{
    /**
     * Regular orders that are not RECEIVED yet: approval or receiving still open.
     *
     * Committing is not a step the store waits on - an approved order is received straight
     * from Inbound Receiving (OrderReceivingService::COMMITTED_STATUSES) - so an approved
     * or partially committed order is simply one that has not been received yet.
     */
    private const ORDER_STAGES = [
        'pending' => ['awaiting approval', 'mass-orders-approval.index'],
        'approved' => ['not yet received', 'orders-receiving.index'],
        'partial_committed' => ['not yet received', 'orders-receiving.index'],
        'committed' => ['not yet received', 'orders-receiving.index'],
        'incomplete' => ['not yet fully received', 'orders-receiving.index'],
    ];

    /**
     * The dates a count is settled over: the period it is read over everywhere else
     * (MonthEndStockVariance::periodFor() - the 1st of the month counted through its MEC
     * Scheduled Date, kept inside that month), ending today while that date is still ahead.
     *
     * It must not run on to today once the date has passed. September's count is taken in
     * October, and an October order still waiting for its delivery then held the September
     * count back, although it belongs to October's.
     *
     * @return array{0: string, 1: string} [Y-m-d from, Y-m-d through]
     */
    private function countPeriod(?MonthEndSchedule $schedule, Carbon $today): array
    {
        $thisMonth = $today->copy()->startOfMonth()->toDateString();

        if (! $schedule) {
            return [$thisMonth, $today->toDateString()];
        }

        [$from, $through] = MonthEndStockVariance::periodFor((int) $schedule->year, (int) $schedule->month, $schedule->calculated_date);

        return [min($from, $thisMonth), min($through, $today->toDateString())];
    }

    /**
     * The period each branch's template covers: that of the count the branch takes next
     * (countPeriod()).
     *
     * That count is the last scheduled one while the branch has not submitted it, else
     * the next one on the schedule. A count is taken after its month has ended (September's
     * on October 1), so the calendar month of today would start a new month with nothing
     * in it and leave every Current SOH at zero.
     *
     * @return array<int, array{0: string, 1: string}> branch id => [Y-m-d from, Y-m-d through]
     */
    public function periods($branchIds, Carbon $today): array
    {
        $branchIds = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->values();

        $last = MonthEndSchedule::where('calculated_date', '<', $today->toDateString())->orderByDesc('calculated_date')->first();
        $next = MonthEndSchedule::where('calculated_date', '>=', $today->toDateString())->orderBy('calculated_date')->first();

        // A rejected count was sent back to the store; it is not a count.
        $submitted = $last && $branchIds->isNotEmpty()
            ? DB::table('month_end_count_items')
                ->where('month_end_schedule_id', $last->id)
                ->whereIn('branch_id', $branchIds->all())
                ->where('status', '!=', 'rejected')
                ->distinct()
                ->pluck('branch_id')
                ->map(fn ($id) => (int) $id)
            : collect();

        $periods = [];
        foreach ($branchIds as $branchId) {
            $owesLast = $last && ! $submitted->contains($branchId);
            $periods[$branchId] = $this->countPeriod($owesLast ? $last : $next, $today);
        }

        return $periods;
    }

    /**
     * Unfinished work per branch, each within its own period.
     *
     * @param  array<int, array{0: string, 1: string}>  $periods  as periods() returns them
     * @return array<int, list<array{key: string, label: string, count: int, url: ?string}>> branch id => blockers
     */
    public function blockersForPeriods(array $periods): array
    {
        $blockers = [];
        $branchesByPeriod = collect($periods)->keys()->groupBy(fn ($branchId) => implode('|', $periods[$branchId]));

        foreach ($branchesByPeriod as $period => $branchIds) {
            [$from, $through] = explode('|', $period);
            $blockers += $this->blockers($branchIds, $from, $through);
        }

        return $blockers;
    }

    /**
     * The period a schedule's count must be settled over before it can be uploaded
     * (countPeriod()). For the count a branch takes next this is the same period its
     * template's Current SOH covers.
     *
     * @return array{0: string, 1: string} [Y-m-d from, Y-m-d through]
     */
    public function uploadPeriod(MonthEndSchedule $schedule, Carbon $today): array
    {
        return $this->countPeriod($schedule, $today);
    }

    /**
     * Unfinished work that keeps a branch from uploading this schedule's count. The count
     * is taken against the template's Current SOH, and the template is withheld while
     * anything in the period is open - so a count uploaded then was not taken on it.
     *
     * @return array<int, list<array{key: string, label: string, count: int, url: ?string}>> branch id => blockers
     */
    public function blockersForUpload(MonthEndSchedule $schedule, $branchIds, Carbon $today): array
    {
        return $this->blockers($branchIds, ...$this->uploadPeriod($schedule, $today));
    }

    /**
     * Unfinished work per branch in the period.
     *
     * @return array<int, list<array{key: string, label: string, count: int, url: ?string}>> branch id => blockers
     */
    public function blockers($branchIds, string $from, string $through): array
    {
        $branchIds = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        $blockers = [];

        if ($branchIds === []) {
            return $blockers;
        }

        $add = function (int $branchId, string $key, int $count, string $label, ?string $route) use (&$blockers) {
            if ($count > 0) {
                $blockers[$branchId][] = [
                    'key' => $key,
                    'label' => $label,
                    'count' => $count,
                    'url' => $route && Route::has($route) ? route($route) : null,
                ];
            }
        };
        $plural = fn (int $count, string $one, string $many) => $count.' '.($count === 1 ? $one : $many);

        // Orders and interco transfers are dated by order_date, as the report's Received is.
        $orders = DB::table('store_orders')
            ->whereNull('interco_number')
            ->whereIn('store_branch_id', $branchIds)
            ->whereBetween('order_date', [$from, $through])
            ->whereIn('order_status', array_keys(self::ORDER_STAGES))
            ->select('store_branch_id', 'order_status', DB::raw('COUNT(*) as total'))
            ->groupBy('store_branch_id', 'order_status')
            ->get();

        $byStage = [];
        foreach ($orders as $row) {
            [$stage, $route] = self::ORDER_STAGES[$row->order_status];
            $byStage[(int) $row->store_branch_id][$stage] = [($byStage[(int) $row->store_branch_id][$stage][0] ?? 0) + (int) $row->total, $route];
        }
        foreach ($byStage as $branchId => $stages) {
            foreach ($stages as $stage => [$count, $route]) {
                $add($branchId, 'orders_'.str_replace(' ', '_', $stage), $count, $plural($count, 'order', 'orders').' '.$stage, $route);
            }
        }

        // A RECEIVED order whose receipt lines are not all approved: the report's Received
        // counts approved lines only.
        $unapprovedReceipts = DB::table('store_orders as so')
            ->join('store_order_items as soi', 'soi.store_order_id', '=', 'so.id')
            ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
            ->whereNull('so.interco_number')
            ->whereIn('so.store_branch_id', $branchIds)
            ->whereBetween('so.order_date', [$from, $through])
            ->where('so.order_status', 'received')
            ->whereIn('oird.status', ['pending', 'received'])
            ->select('so.store_branch_id', DB::raw('COUNT(DISTINCT so.id) as total'))
            ->groupBy('so.store_branch_id')
            ->get();
        foreach ($unapprovedReceipts as $row) {
            $add((int) $row->store_branch_id, 'receipt_approval', (int) $row->total,
                $plural((int) $row->total, 'delivery', 'deliveries').' with a receipt awaiting approval', 'receiving-approvals.index');
        }

        // Interco: the receiving store waits until its transfer is received. The sending
        // store is not held to committing what it sends - by request, no commit of any kind
        // stands between a store and its count.
        $intercoIncoming = DB::table('store_orders')
            ->whereNotNull('interco_number')
            ->whereIn('store_branch_id', $branchIds)
            ->whereBetween('order_date', [$from, $through])
            ->whereIn('interco_status', ['open', 'approved', 'committed', 'in_transit'])
            ->select('store_branch_id', DB::raw('COUNT(*) as total'))
            ->groupBy('store_branch_id')
            ->get();
        foreach ($intercoIncoming as $row) {
            $add((int) $row->store_branch_id, 'interco_receive', (int) $row->total,
                $plural((int) $row->total, 'interco transfer', 'interco transfers').' not yet received', 'interco-receiving.index');
        }

        // Wastage is dated by created_at, as the report's Wastage is.
        $wastageLevels = [
            'pending' => ['wastage_level1', 'awaiting level 1 approval', 'wastage-approval-lvl1.index'],
            'approved_lvl1' => ['wastage_level2', 'awaiting level 2 approval', 'wastage-approval-lvl2.index'],
        ];
        $wastages = DB::table('wastages')
            ->whereIn('store_branch_id', $branchIds)
            ->whereBetween('created_at', [$from.' 00:00:00', $through.' 23:59:59'])
            ->whereIn('wastage_status', array_keys($wastageLevels))
            ->select('store_branch_id', 'wastage_status', DB::raw('COUNT(DISTINCT wastage_no) as total'))
            ->groupBy('store_branch_id', 'wastage_status')
            ->get();
        foreach ($wastages as $row) {
            [$key, $stage, $route] = $wastageLevels[$row->wastage_status];
            $add((int) $row->store_branch_id, $key, (int) $row->total,
                $plural((int) $row->total, 'wastage report', 'wastage reports').' '.$stage, $route);
        }

        // The previous month's count is the Beginning Balance; it must be fully approved.
        $previousMonth = Carbon::parse($from)->subMonthNoOverflow();
        $previous = MonthEndSchedule::where('year', $previousMonth->year)->where('month', $previousMonth->month)->first();
        if ($previous) {
            $counts = DB::table('month_end_count_items')
                ->where('month_end_schedule_id', $previous->id)
                ->whereIn('branch_id', $branchIds)
                ->where('status', '!=', 'level2_approved')
                ->select('branch_id', DB::raw('MIN(status) as status'))
                ->groupBy('branch_id')
                ->get();
            foreach ($counts as $row) {
                $add((int) $row->branch_id, 'previous_count', 1,
                    $previousMonth->format('F Y').' month end count is not fully approved yet ('.str_replace('_', ' ', $row->status).')',
                    'month-end-count-approvals.index');
            }
        }

        // Unapproved SOH adjustments do not block the count either (removed by request).

        return $blockers;
    }
}
