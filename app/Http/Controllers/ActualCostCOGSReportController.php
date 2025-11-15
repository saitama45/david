<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\User;
use App\Models\SAPMasterfile;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\SupplierItems;
use App\Exports\ActualCostCOGSReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class ActualCostCOGSReportController extends Controller
{
    /**
     * Display the Actual Cost COGS Report page.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Get filter parameters
        $filters = $request->only([
            'year',
            'month',
            'store_ids',
            'search',
            'per_page'
        ]);

        // Set default values
        $filters['year'] = $filters['year'] ?? Carbon::now()->year;
        $filters['month'] = $filters['month'] ?? Carbon::now()->month;
        $filters['per_page'] = $filters['per_page'] ?? 50;
        
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');
        $filters['store_ids'] = $request->input('store_ids', $assignedStoreIds->toArray());

        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get(['id', 'name', 'brand_code']);

        if (empty($filters['store_ids'])) {
            $filters['store_ids'] = $assignedStoreIds->toArray();
        }
        $filters['store_ids'] = array_intersect($filters['store_ids'], $assignedStoreIds->toArray());

        $previousMonth = Carbon::create($filters['year'], $filters['month'])->subMonth();

        $prevItemsSubquery = MonthEndCountItem::from('month_end_count_items as ci_total')
            ->select('ci_total.total_qty', 'ci_total.sap_masterfile_id', 'ci_total.branch_id')
            ->join('month_end_schedules as ci_sched', 'ci_total.month_end_schedule_id', '=', 'ci_sched.id')
            ->where('ci_sched.year', $previousMonth->year)
            ->where('ci_sched.month', $previousMonth->month)
            ->whereNotNull('ci_total.level2_approved_at');

        $deliveriesSubquery = StoreOrderItem::from('store_order_items as soi')
            ->select('soi.sap_masterfile_id', 'so.store_branch_id', DB::raw('SUM(soi.quantity_received) as total_qty'))
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.order_status', 'received')
            ->where(function ($q) {
                $q->whereNull('so.variant')->orWhere('so.variant', '!=', 'INTERCO');
            })
            ->whereYear('so.order_date', $filters['year'])
            ->whereMonth('so.order_date', $filters['month'])
            ->groupBy('soi.sap_masterfile_id', 'so.store_branch_id');

        $intercoSubquery = StoreOrderItem::from('store_order_items as soi')
            ->select('soi.sap_masterfile_id', 'so.store_branch_id', DB::raw('SUM(soi.quantity_received) as total_qty'))
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.order_status', 'received')
            ->where('so.variant', 'INTERCO')
            ->where('so.interco_status', 'received')
            ->whereYear('so.order_date', $filters['year'])
            ->whereMonth('so.order_date', $filters['month'])
            ->groupBy('soi.sap_masterfile_id', 'so.store_branch_id');

        $baseQuery = DB::table('month_end_count_items as meci')
            ->select([
                'sb.id as store_id',
                DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_branch"),
                'sm.ItemCode as item_code',
                'sm.ItemDescription as item_description',
                'sm.BaseUOM as uom',
                DB::raw('COALESCE(prev_items.total_qty, 0) as beginning_inventory'),
                DB::raw('COALESCE(deliveries.total_qty, 0) as deliveries'),
                DB::raw('COALESCE(interco.total_qty, 0) as interco'),
                'meci.total_qty as ending_inventory',
                DB::raw('(SELECT TOP 1 cost FROM supplier_items si WHERE si.ItemCode = sm.ItemCode AND si.is_active = 1 ORDER BY si.id DESC) as unit_cost')
            ])
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('sap_masterfiles as sm', 'meci.sap_masterfile_id', '=', 'sm.id')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->leftJoinSub($prevItemsSubquery, 'prev_items', function ($join) {
                $join->on('prev_items.sap_masterfile_id', '=', 'sm.id')
                     ->on('prev_items.branch_id', '=', 'meci.branch_id');
            })
            ->leftJoinSub($deliveriesSubquery, 'deliveries', function ($join) {
                $join->on('deliveries.sap_masterfile_id', '=', 'sm.id')
                     ->on('deliveries.store_branch_id', '=', 'meci.branch_id');
            })
            ->leftJoinSub($intercoSubquery, 'interco', function ($join) {
                $join->on('interco.sap_masterfile_id', '=', 'sm.id')
                     ->on('interco.store_branch_id', '=', 'meci.branch_id');
            })
            ->where('mes.year', $filters['year'])
            ->where('mes.month', $filters['month'])
            ->whereNotNull('meci.level2_approved_at')
            ->where('sb.is_active', true)
            ->whereIn('sb.id', $filters['store_ids']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $baseQuery->where(function($q) use ($search) {
                $q->where('sm.ItemCode', 'like', "%{$search}%")
                  ->orWhere('sm.ItemDescription', 'like', "%{$search}%")
                  ->orWhere('sb.name', 'like', "%{$search}%")
                  ->orWhere('sb.branch_code', 'like', "%{$search}%");
            });
        }

        $fullQuery = DB::table(DB::raw("({$baseQuery->toSql()}) as base"))
            ->mergeBindings($baseQuery)
            ->select(
                'base.*',
                DB::raw('base.beginning_inventory * base.unit_cost as beginning_value'),
                DB::raw('base.deliveries * base.unit_cost as deliveries_value'),
                DB::raw('base.interco * base.unit_cost as interco_value'),
                DB::raw('base.ending_inventory * base.unit_cost as ending_value'),
                DB::raw('base.beginning_inventory + base.deliveries + base.interco - base.ending_inventory as actual_cost')
            )
            ->where(function ($q) {
                $q->where('base.beginning_inventory', '>', 0)
                  ->orWhere('base.deliveries', '>', 0)
                  ->orWhere('base.interco', '>', 0)
                  ->orWhere('base.ending_inventory', '>', 0);
            });

        $paginatedData = $fullQuery->orderBy('store_branch')->orderBy('item_code')->paginate($filters['per_page']);

        return Inertia::render('Reports/ActualCostCOGSReport/Index', [
            'reportData' => $paginatedData->items(),
            'paginatedData' => $paginatedData,
            'filters' => $filters,
            'stores' => $stores,
            'assignedStoreIds' => $assignedStoreIds,
            'monthOptions' => [
                ['label' => 'January', 'value' => 1],
                ['label' => 'February', 'value' => 2],
                ['label' => 'March', 'value' => 3],
                ['label' => 'April', 'value' => 4],
                ['label' => 'May', 'value' => 5],
                ['label' => 'June', 'value' => 6],
                ['label' => 'July', 'value' => 7],
                ['label' => 'August', 'value' => 8],
                ['label' => 'September', 'value' => 9],
                ['label' => 'October', 'value' => 10],
                ['label' => 'November', 'value' => 11],
                ['label' => 'December', 'value' => 12],
            ]
        ]);
    }

    /**
     * Export Actual Cost COGS Report to Excel.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        $filters = $request->only(['year', 'month', 'store_ids', 'search']);

        $filters['year'] = $filters['year'] ?? Carbon::now()->year;
        $filters['month'] = $filters['month'] ?? Carbon::now()->month;
        
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');
        $filters['store_ids'] = $request->input('store_ids', $assignedStoreIds->toArray());

        if (empty($filters['store_ids'])) {
            $filters['store_ids'] = $assignedStoreIds->toArray();
        }
        $filters['store_ids'] = array_intersect($filters['store_ids'], $assignedStoreIds->toArray());

        $previousMonth = Carbon::create($filters['year'], $filters['month'])->subMonth();

        $prevItemsSubquery = MonthEndCountItem::from('month_end_count_items as ci_total')
            ->select('ci_total.total_qty', 'ci_total.sap_masterfile_id', 'ci_total.branch_id')
            ->join('month_end_schedules as ci_sched', 'ci_total.month_end_schedule_id', '=', 'ci_sched.id')
            ->where('ci_sched.year', $previousMonth->year)
            ->where('ci_sched.month', $previousMonth->month)
            ->whereNotNull('ci_total.level2_approved_at');

        $deliveriesSubquery = StoreOrderItem::from('store_order_items as soi')
            ->select('soi.sap_masterfile_id', 'so.store_branch_id', DB::raw('SUM(soi.quantity_received) as total_qty'))
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.order_status', 'received')
            ->where(function ($q) {
                $q->whereNull('so.variant')->orWhere('so.variant', '!=', 'INTERCO');
            })
            ->whereYear('so.order_date', $filters['year'])
            ->whereMonth('so.order_date', $filters['month'])
            ->groupBy('soi.sap_masterfile_id', 'so.store_branch_id');

        $intercoSubquery = StoreOrderItem::from('store_order_items as soi')
            ->select('soi.sap_masterfile_id', 'so.store_branch_id', DB::raw('SUM(soi.quantity_received) as total_qty'))
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.order_status', 'received')
            ->where('so.variant', 'INTERCO')
            ->where('so.interco_status', 'received')
            ->whereYear('so.order_date', $filters['year'])
            ->whereMonth('so.order_date', $filters['month'])
            ->groupBy('soi.sap_masterfile_id', 'so.store_branch_id');

        $baseQuery = DB::table('month_end_count_items as meci')
            ->select([
                'sb.id as store_id',
                DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_branch"),
                'sm.ItemCode as item_code',
                'sm.ItemDescription as item_description',
                'sm.BaseUOM as uom',
                DB::raw('COALESCE(prev_items.total_qty, 0) as beginning_inventory'),
                DB::raw('COALESCE(deliveries.total_qty, 0) as deliveries'),
                DB::raw('COALESCE(interco.total_qty, 0) as interco'),
                'meci.total_qty as ending_inventory',
                DB::raw('(SELECT TOP 1 cost FROM supplier_items si WHERE si.ItemCode = sm.ItemCode AND si.is_active = 1 ORDER BY si.id DESC) as unit_cost')
            ])
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('sap_masterfiles as sm', 'meci.sap_masterfile_id', '=', 'sm.id')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->leftJoinSub($prevItemsSubquery, 'prev_items', function ($join) {
                $join->on('prev_items.sap_masterfile_id', '=', 'sm.id')
                     ->on('prev_items.branch_id', '=', 'meci.branch_id');
            })
            ->leftJoinSub($deliveriesSubquery, 'deliveries', function ($join) {
                $join->on('deliveries.sap_masterfile_id', '=', 'sm.id')
                     ->on('deliveries.store_branch_id', '=', 'meci.branch_id');
            })
            ->leftJoinSub($intercoSubquery, 'interco', function ($join) {
                $join->on('interco.sap_masterfile_id', '=', 'sm.id')
                     ->on('interco.store_branch_id', '=', 'meci.branch_id');
            })
            ->where('mes.year', $filters['year'])
            ->where('mes.month', $filters['month'])
            ->whereNotNull('meci.level2_approved_at')
            ->where('sb.is_active', true)
            ->whereIn('sb.id', $filters['store_ids']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $baseQuery->where(function($q) use ($search) {
                $q->where('sm.ItemCode', 'like', "%{$search}%")
                  ->orWhere('sm.ItemDescription', 'like', "%{$search}%")
                  ->orWhere('sb.name', 'like', "%{$search}%")
                  ->orWhere('sb.branch_code', 'like', "%{$search}%");
            });
        }

        $fullQuery = DB::table(DB::raw("({$baseQuery->toSql()}) as base"))
            ->mergeBindings($baseQuery)
            ->select(
                'base.*',
                DB::raw('base.beginning_inventory * base.unit_cost as beginning_value'),
                DB::raw('base.deliveries * base.unit_cost as deliveries_value'),
                DB::raw('base.interco * base.unit_cost as interco_value'),
                DB::raw('base.ending_inventory * base.unit_cost as ending_value'),
                DB::raw('base.beginning_inventory + base.deliveries + base.interco - base.ending_inventory as actual_cost')
            )
            ->where(function ($q) {
                $q->where('base.beginning_inventory', '>', 0)
                  ->orWhere('base.deliveries', '>', 0)
                  ->orWhere('base.interco', '>', 0)
                  ->orWhere('base.ending_inventory', '>', 0);
            });
            
        $reportData = $fullQuery->orderBy('store_branch')->orderBy('item_code')->get();

        return Excel::download(
            new ActualCostCOGSReportExport($reportData),
            'actual-cost-cogs-report-' . $filters['year'] . '-' . str_pad($filters['month'], 2, '0', STR_PAD_LEFT) . '.xlsx'
        );
    }
}