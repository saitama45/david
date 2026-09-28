<?php

namespace App\Http\Services;

use App\Models\MonthEndSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * What still has to be finished before a store's Month End Count template can carry a
 * trustworthy Current SOH.
 *
 * Current SOH is the Inventory Movement Report's Theoretical SOH for the month to date.
 * That figure only counts approved receipts, level 2 approved wastage and the previous
 * month's count, so any order, transfer, wastage or count still open in the period would
 * leave it wrong. The template is withheld until the store's period is settled.
 */
class MonthEndCountReadinessService
{
    /** Regular orders that are not RECEIVED yet: approval, commit or receiving still open. */
    private const ORDER_STAGES = [
        'pending' => ['awaiting approval', 'mass-orders-approval.index'],
        'approved' => ['awaiting commit', null],
        'partial_committed' => ['awaiting commit', null],
        'committed' => ['not yet received', 'orders-receiving.index'],
        'incomplete' => ['not yet fully received', 'orders-receiving.index'],
    ];

    /**
     * The period the template's Current SOH covers: the first day of this month through
     * today, the same range the report defaults to.
     *
     * @return array{0: string, 1: string} Y-m-d from, Y-m-d through
     */
    public function period(Carbon $today): array
    {
        return [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
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

        // Interco: the receiving store waits until it is received, the sending store until
        // it has committed what it sends.
        $intercoSides = [
            'interco_receive' => ['store_branch_id', ['open', 'approved', 'committed', 'in_transit'], 'not yet received', 'interco-receiving.index'],
            'interco_send' => ['sending_store_branch_id', ['open', 'approved'], 'not yet committed by this store', 'store-commits.index'],
        ];
        foreach ($intercoSides as $key => [$column, $statuses, $stage, $route]) {
            $rows = DB::table('store_orders')
                ->whereNotNull('interco_number')
                ->whereIn($column, $branchIds)
                ->whereBetween('order_date', [$from, $through])
                ->whereIn('interco_status', $statuses)
                ->select("{$column} as branch_id", DB::raw('COUNT(*) as total'))
                ->groupBy($column)
                ->get();
            foreach ($rows as $row) {
                $add((int) $row->branch_id, $key, (int) $row->total,
                    $plural((int) $row->total, 'interco transfer', 'interco transfers').' '.$stage, $route);
            }
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

        // SOH adjustments waiting for their approver.
        $adjustments = DB::table('product_inventory_stock_managers')
            ->whereIn('store_branch_id', $branchIds)
            ->where('action', 'soh_adjustment')
            ->where('is_stock_adjustment_approved', false)
            ->whereBetween('transaction_date', [$from.' 00:00:00', $through.' 23:59:59'])
            ->select('store_branch_id', DB::raw('COUNT(*) as total'))
            ->groupBy('store_branch_id')
            ->get();
        foreach ($adjustments as $row) {
            $add((int) $row->store_branch_id, 'soh_adjustment', (int) $row->total,
                $plural((int) $row->total, 'SOH adjustment', 'SOH adjustments').' awaiting approval', 'soh-adjustment.index');
        }

        return $blockers;
    }
}
