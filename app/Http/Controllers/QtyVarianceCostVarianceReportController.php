<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\StoreBranch;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Models\SAPMasterfile;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreTransaction;
use App\Models\Wastage;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\QtyVarianceCostVarianceReportExport;
use Inertia\Response;

class QtyVarianceCostVarianceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $assignedStoreIds = $this->getAssignedStoreIds($user);

        // Get stores for filter dropdown (Only active ones)
        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_code', 'brand_code']);
        
        $activeAssignedStoreIds = $stores->pluck('id')->toArray();

        // Get filters from request
        $requestedStoreIds = $request->get('store_ids');
        if ($requestedStoreIds) {
            // Convert to array if string and cast to integers
            $requestedStoreIds = is_array($requestedStoreIds) ? $requestedStoreIds : [$requestedStoreIds];
            $requestedStoreIds = array_map('intval', $requestedStoreIds);
            // Filter only to those that are active and assigned
            $storeIdsFilter = array_intersect($requestedStoreIds, $activeAssignedStoreIds);
        } else {
            $storeIdsFilter = $activeAssignedStoreIds;
        }

        $filters = [
            'date_from' => $request->get('date_from', now()->startOfMonth()->format('Y-m-d')),
            'date_to' => $request->get('date_to', now()->format('Y-m-d')),
            'store_ids' => $storeIdsFilter,
            'search' => $request->get('search', ''),
            'per_page' => $request->get('per_page', 50),
            'sort_field' => $request->get('sort_field', ''),
            'sort_direction' => $request->get('sort_direction', 'asc'),
            'filter_store' => $request->get('filter_store', ''),
            'filter_item_code' => $request->get('filter_item_code', ''),
            'filter_item_description' => $request->get('filter_item_description', ''),
            'filter_uom' => $request->get('filter_uom', ''),
        ];

        // Extract year and month from date_from filter
        $filterDate = Carbon::parse($filters['date_from']);
        $filterYear = $filterDate->year;
        $filterMonth = $filterDate->month;

        $prevMonthDate = $filterDate->copy()->subMonth();
        $dateFrom = $filterDate->copy()->startOfMonth()->format('Y-m-d');
        $dateTo = $filterDate->copy()->endOfMonth()->format('Y-m-d');
        $wastageStatus = \App\Enums\WastageStatus::APPROVED_LVL2->value;

        $theoreticalInventorySubquery = DB::raw("(
            COALESCE((
                SELECT meci_prev.total_qty
                FROM month_end_count_items meci_prev
                JOIN month_end_schedules mes_prev ON meci_prev.month_end_schedule_id = mes_prev.id
                WHERE meci_prev.branch_id = meci.branch_id
                  AND meci_prev.item_code = meci.item_code
                  AND mes_prev.year = {$prevMonthDate->year}
                  AND mes_prev.month = {$prevMonthDate->month}
            ), 0)
            +
            COALESCE((
                SELECT SUM(oird.quantity_received)
                FROM ordered_item_receive_dates oird
                JOIN store_order_items soi ON oird.store_order_item_id = soi.id
                JOIN store_orders so ON soi.store_order_id = so.id
                WHERE so.store_branch_id = meci.branch_id
                  AND soi.item_code = meci.item_code
                  AND so.interco_number IS NULL
                  AND oird.status = 'approved'
                  AND oird.received_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
            ), 0)
            +
            COALESCE((
                SELECT SUM(oird.quantity_received)
                FROM ordered_item_receive_dates oird
                JOIN store_order_items soi ON oird.store_order_item_id = soi.id
                JOIN store_orders so ON soi.store_order_id = so.id
                WHERE so.store_branch_id = meci.branch_id
                  AND soi.item_code = meci.item_code
                  AND so.interco_number IS NOT NULL
                  AND oird.status = 'approved'
                  AND oird.received_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
            ), 0)
            -
            COALESCE((
                SELECT SUM(COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0))
                FROM store_transaction_items sti
                JOIN store_transactions st ON sti.store_transaction_id = st.id
                JOIN pos_masterfiles pm ON sti.product_id = pm.id
                JOIN pos_masterfiles_bom bom ON pm.POSCode = bom.POSCode
                WHERE st.store_branch_id = meci.branch_id
                  AND bom.ItemCode = meci.item_code
                  AND st.order_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
            ), 0)
            -
            COALESCE((
                SELECT SUM(w.wastage_qty)
                FROM wastages w
                JOIN sap_masterfiles sap ON w.sap_masterfile_id = sap.id
                WHERE w.store_branch_id = meci.branch_id
                  AND sap.ItemCode = meci.item_code
                  AND w.wastage_status = '{$wastageStatus}'
                  AND w.created_at BETWEEN '{$dateFrom} 00:00:00' AND '{$dateTo} 23:59:59'
            ), 0)
        ) as theoretical_inventory");

        $query = MonthEndCountItem::query()
            ->from('month_end_count_items as meci')
            ->select(
                'meci.id',
                DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_name"),
                'sm.ItemCode as item_code',
                'sm.ItemDescription as item_description',
                'sm.BaseUOM as uom',
                'meci.total_qty as actual_inventory',
                $theoreticalInventorySubquery,
                // Subquery to get the cost from the latest active supplier item
                DB::raw('(SELECT TOP 1 cost FROM supplier_items si WHERE si.ItemCode = sm.ItemCode AND si.is_active = 1 ORDER BY si.id DESC) as cost')
            )
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('sap_masterfiles as sm', 'meci.sap_masterfile_id', '=', 'sm.id')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->whereNotNull('meci.level2_approved_at')
            ->whereIn('meci.branch_id', $filters['store_ids'])
            ->where('mes.year', $filterYear)
            ->where('mes.month', $filterMonth);

        if ($filters['search']) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('sm.ItemCode', 'like', "%{$search}%")
                ->orWhere('sm.ItemDescription', 'like', "%{$search}%")
                ->orWhere('sb.name', 'like', "%{$search}%")
                ->orWhere('sb.branch_code', 'like', "%{$search}%");
            });
        }

        // Apply column-specific filters
        if ($filters['filter_store']) {
            $query->where('sb.name', 'like', "%{$filters['filter_store']}%");
        }
        if ($filters['filter_item_code']) {
            $query->where('sm.ItemCode', 'like', "%{$filters['filter_item_code']}%");
        }
        if ($filters['filter_item_description']) {
            $query->where('sm.ItemDescription', 'like', "%{$filters['filter_item_description']}%");
        }
        if ($filters['filter_uom']) {
            $query->where('sm.BaseUOM', 'like', "%{$filters['filter_uom']}%");
        }

        $varianceDataRaw = $query->get();

        $varianceData = $varianceDataRaw->map(function ($item) {
            $cost = (float) ($item->cost ?? 0);
            $actualInventory = (float) $item->actual_inventory;
            $theoreticalInventory = (float) $item->theoretical_inventory;

            $qtyVariance = $actualInventory - $theoreticalInventory;
            $actualCost = $cost * $actualInventory;
            $theoreticalCost = $cost * $theoreticalInventory;
            $costVariance = $actualCost - $theoreticalCost;

            return [
                'id' => $item->id,
                'store_name' => $item->store_name,
                'item_code' => $item->item_code,
                'item_description' => $item->item_description,
                'uom' => $item->uom,
                'cost' => $cost,
                'actual_inventory' => $actualInventory,
                'theoretical_inventory' => $theoreticalInventory,
                'qty_variance' => $qtyVariance,
                'actual_cost' => $actualCost,
                'theoretical_cost' => $theoreticalCost,
                'cost_variance' => $costVariance,
            ];
        });

        // Apply Sorting to the collection
        if ($filters['sort_field']) {
            $sortField = $filters['sort_field'];
            $sortDir = $filters['sort_direction'] === 'desc';
            
            $varianceData = $varianceData->sortBy($sortField, SORT_REGULAR, $sortDir);
        } else {
            // Default sort by store name
            $varianceData = $varianceData->sortBy('store_name');
        }

        // Paginate the mapped data
        $currentPage = LengthAwarePaginator::resolveCurrentPage() ?: 1;
        $perPage = (int) $filters['per_page'];
        $total = $varianceData->count();
        $currentItems = $varianceData->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginatedData = new LengthAwarePaginator(
            $currentItems,
            $total,
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return Inertia::render('Reports/QtyVarianceCostVarianceReport/Index', [
            'varianceData' => $varianceData->values(), // For totals calculation on frontend
            'paginatedData' => $paginatedData,
            'filters' => $filters,
            'stores' => $stores,
            'assignedStoreIds' => $activeAssignedStoreIds,
        ]);
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $assignedStoreIds = $this->getAssignedStoreIds($user);

        // Get stores for filter (Only active ones)
        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->where('is_active', true)
            ->get(['id']);
        
        $activeAssignedStoreIds = $stores->pluck('id')->toArray();

        // Get filters from request
        $requestedStoreIds = $request->get('store_ids');
        if ($requestedStoreIds) {
            $requestedStoreIds = is_array($requestedStoreIds) ? $requestedStoreIds : [$requestedStoreIds];
            $requestedStoreIds = array_map('intval', $requestedStoreIds);
            $storeIdsFilter = array_intersect($requestedStoreIds, $activeAssignedStoreIds);
        } else {
            $storeIdsFilter = $activeAssignedStoreIds;
        }

        $filters = [
            'date_from' => $request->get('date_from', now()->startOfMonth()->format('Y-m-d')),
            'date_to' => $request->get('date_to', now()->format('Y-m-d')),
            'store_ids' => $storeIdsFilter,
            'search' => $request->get('search', ''),
            'sort_field' => $request->get('sort_field', ''),
            'sort_direction' => $request->get('sort_direction', 'asc'),
            'filter_store' => $request->get('filter_store', ''),
            'filter_item_code' => $request->get('filter_item_code', ''),
            'filter_item_description' => $request->get('filter_item_description', ''),
            'filter_uom' => $request->get('filter_uom', ''),
        ];

        // Extract year and month from date_from filter
        $filterDate = Carbon::parse($filters['date_from']);
        $filterYear = $filterDate->year;
        $filterMonth = $filterDate->month;

        $prevMonthDate = $filterDate->copy()->subMonth();
        $dateFrom = $filterDate->copy()->startOfMonth()->format('Y-m-d');
        $dateTo = $filterDate->copy()->endOfMonth()->format('Y-m-d');
        $wastageStatus = \App\Enums\WastageStatus::APPROVED_LVL2->value;

        $theoreticalInventorySubquery = DB::raw("(
            COALESCE((
                SELECT meci_prev.total_qty
                FROM month_end_count_items meci_prev
                JOIN month_end_schedules mes_prev ON meci_prev.month_end_schedule_id = mes_prev.id
                WHERE meci_prev.branch_id = meci.branch_id
                  AND meci_prev.item_code = meci.item_code
                  AND mes_prev.year = {$prevMonthDate->year}
                  AND mes_prev.month = {$prevMonthDate->month}
            ), 0)
            +
            COALESCE((
                SELECT SUM(oird.quantity_received)
                FROM ordered_item_receive_dates oird
                JOIN store_order_items soi ON oird.store_order_item_id = soi.id
                JOIN store_orders so ON soi.store_order_id = so.id
                WHERE so.store_branch_id = meci.branch_id
                  AND soi.item_code = meci.item_code
                  AND so.interco_number IS NULL
                  AND oird.status = 'approved'
                  AND oird.received_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
            ), 0)
            +
            COALESCE((
                SELECT SUM(oird.quantity_received)
                FROM ordered_item_receive_dates oird
                JOIN store_order_items soi ON oird.store_order_item_id = soi.id
                JOIN store_orders so ON soi.store_order_id = so.id
                WHERE so.store_branch_id = meci.branch_id
                  AND soi.item_code = meci.item_code
                  AND so.interco_number IS NOT NULL
                  AND oird.status = 'approved'
                  AND oird.received_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
            ), 0)
            -
            COALESCE((
                SELECT SUM(COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0))
                FROM store_transaction_items sti
                JOIN store_transactions st ON sti.store_transaction_id = st.id
                JOIN pos_masterfiles pm ON sti.product_id = pm.id
                JOIN pos_masterfiles_bom bom ON pm.POSCode = bom.POSCode
                WHERE st.store_branch_id = meci.branch_id
                  AND bom.ItemCode = meci.item_code
                  AND st.order_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
            ), 0)
            -
            COALESCE((
                SELECT SUM(w.wastage_qty)
                FROM wastages w
                JOIN sap_masterfiles sap ON w.sap_masterfile_id = sap.id
                WHERE w.store_branch_id = meci.branch_id
                  AND sap.ItemCode = meci.item_code
                  AND w.wastage_status = '{$wastageStatus}'
                  AND w.created_at BETWEEN '{$dateFrom} 00:00:00' AND '{$dateTo} 23:59:59'
            ), 0)
        ) as theoretical_inventory");

        $query = MonthEndCountItem::query()
            ->from('month_end_count_items as meci')
            ->select(
                'meci.id',
                DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_name"),
                'sm.ItemCode as item_code',
                'sm.ItemDescription as item_description',
                'sm.BaseUOM as uom',
                'meci.total_qty as actual_inventory',
                $theoreticalInventorySubquery,
                DB::raw('(SELECT TOP 1 cost FROM supplier_items si WHERE si.ItemCode = sm.ItemCode AND si.is_active = 1 ORDER BY si.id DESC) as cost')
            )
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('sap_masterfiles as sm', 'meci.sap_masterfile_id', '=', 'sm.id')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->whereNotNull('meci.level2_approved_at')
            ->whereIn('meci.branch_id', $filters['store_ids'])
            ->where('mes.year', $filterYear)
            ->where('mes.month', $filterMonth);

        if ($filters['search']) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('sm.ItemCode', 'like', "%{$search}%")
                ->orWhere('sm.ItemDescription', 'like', "%{$search}%")
                ->orWhere('sb.name', 'like', "%{$search}%")
                ->orWhere('sb.branch_code', 'like', "%{$search}%");
            });
        }

        // Apply column-specific filters
        if ($filters['filter_store']) {
            $query->where('sb.name', 'like', "%{$filters['filter_store']}%");
        }
        if ($filters['filter_item_code']) {
            $query->where('sm.ItemCode', 'like', "%{$filters['filter_item_code']}%");
        }
        if ($filters['filter_item_description']) {
            $query->where('sm.ItemDescription', 'like', "%{$filters['filter_item_description']}%");
        }
        if ($filters['filter_uom']) {
            $query->where('sm.BaseUOM', 'like', "%{$filters['filter_uom']}%");
        }

        $varianceDataRaw = $query->get();

        $varianceData = $varianceDataRaw->map(function ($item) {
            $cost = (float) ($item->cost ?? 0);
            $actualInventory = (float) $item->actual_inventory;
            $theoreticalInventory = (float) $item->theoretical_inventory;

            $qtyVariance = $actualInventory - $theoreticalInventory;
            $actualCost = $cost * $actualInventory;
            $theoreticalCost = $cost * $theoreticalInventory;
            $costVariance = $actualCost - $theoreticalCost;

            return [
                'id' => $item->id,
                'store_name' => $item->store_name,
                'item_code' => $item->item_code,
                'item_description' => $item->item_description,
                'uom' => $item->uom,
                'cost' => $cost,
                'actual_inventory' => $actualInventory,
                'theoretical_inventory' => $theoreticalInventory,
                'qty_variance' => $qtyVariance,
                'actual_cost' => $actualCost,
                'theoretical_cost' => $theoreticalCost,
                'cost_variance' => $costVariance,
            ];
        });

        // Apply Sorting
        if ($filters['sort_field']) {
            $sortField = $filters['sort_field'];
            $sortDir = $filters['sort_direction'] === 'desc';
            $varianceData = $varianceData->sortBy($sortField, SORT_REGULAR, $sortDir);
        } else {
            $varianceData = $varianceData->sortBy('store_name');
        }

        $filename = 'qty_variance_cost_variance_report_' . Carbon::now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new QtyVarianceCostVarianceReportExport($varianceData->toArray()), $filename);
    }

    public function getBreakdown($id)
    {
        $assignedStoreIds = $this->getAssignedStoreIds(Auth::user());

        $meci = MonthEndCountItem::with(['schedule', 'sapMasterfile'])
            ->whereIn('branch_id', $assignedStoreIds)
            ->findOrFail($id);
        
        $sapItem = $meci->sapMasterfile;
        $branchId = $meci->branch_id;
        $itemCode = $meci->item_code;
        $schedule = $meci->schedule;
        
        $currMonthDate = Carbon::create($schedule->year, $schedule->month, 1);
        $prevMonthDate = $currMonthDate->copy()->subMonth();
        
        $dateFrom = $currMonthDate->startOfMonth()->format('Y-m-d');
        $dateTo = $currMonthDate->copy()->endOfMonth()->format('Y-m-d');
        
        // 1. Beginning Balance
        $begBal = MonthEndCountItem::where('branch_id', $branchId)
            ->where('item_code', $itemCode)
            ->whereHas('schedule', function($q) use ($prevMonthDate) {
                $q->where('year', $prevMonthDate->year)
                  ->where('month', $prevMonthDate->month);
            })
            ->value('total_qty') ?? 0;
            
        // 2. Regular Received (Procurement Received where Interco is Null)
        $regularReceived = DB::table('ordered_item_receive_dates as oird')
            ->join('store_order_items as soi', 'oird.store_order_item_id', '=', 'soi.id')
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.store_branch_id', $branchId)
            ->where('soi.item_code', $itemCode)
            ->whereNull('so.interco_number')
            ->where('oird.status', 'approved')
            ->whereBetween('oird.received_date', [$dateFrom, $dateTo])
            ->sum('oird.quantity_received');
            
        // 3. CPO Received (Procurement Received where Interco is NOT Null)
        $cpoReceived = DB::table('ordered_item_receive_dates as oird')
            ->join('store_order_items as soi', 'oird.store_order_item_id', '=', 'soi.id')
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.store_branch_id', $branchId)
            ->where('soi.item_code', $itemCode)
            ->whereNotNull('so.interco_number')
            ->where('oird.status', 'approved')
            ->whereBetween('oird.received_date', [$dateFrom, $dateTo])
            ->sum('oird.quantity_received');
            
        // 4. Sales
        $sales = DB::table('store_transaction_items as sti')
            ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
            ->join('pos_masterfiles as pm', 'sti.product_id', '=', 'pm.id')
            ->join('pos_masterfiles_bom as bom', 'pm.POSCode', '=', 'bom.POSCode')
            ->where('st.store_branch_id', $branchId)
            ->where('bom.ItemCode', $itemCode)
            ->whereBetween('st.order_date', [$dateFrom, $dateTo])
            ->sum(DB::raw('COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0)'));
            
        // 5. Wastage
        $wastage = Wastage::join('sap_masterfiles as sap', 'wastages.sap_masterfile_id', '=', 'sap.id')
            ->where('wastages.store_branch_id', $branchId)
            ->where('sap.ItemCode', $itemCode)
            ->where('wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
            ->whereBetween('wastages.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->sum('wastages.wastage_qty');

        return response()->json([
            'actual_mec' => (float)$meci->total_qty,
            'beg_bal' => (float)$begBal,
            'regular_received' => (float)$regularReceived,
            'cpo_received' => (float)$cpoReceived,
            'sales' => (float)$sales,
            'wastage' => (float)$wastage,
            'theoretical' => (float)($begBal + $regularReceived + $cpoReceived - $sales - $wastage)
        ]);
    }

    private function getAssignedStoreIds($user)
    {
        return \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();
    }
}
