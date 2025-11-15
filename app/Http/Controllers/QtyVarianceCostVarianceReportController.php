<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\StoreBranch;
use App\Models\MonthEndCountItem;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class QtyVarianceCostVarianceReportController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $assignedStoreIds = $this->getAssignedStoreIds($user);

        // Get stores for filter dropdown
        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_code']);

        // Get filters from request
        $filters = [
            'date_from' => $request->get('date_from', now()->startOfMonth()->format('Y-m-d')),
            'date_to' => $request->get('date_to', now()->format('Y-m-d')),
            'store_ids' => $request->get('store_ids', $assignedStoreIds),
            'search' => $request->get('search', ''),
            'per_page' => $request->get('per_page', 50),
        ];

        // Extract year and month from date_from filter
        $filterDate = Carbon::parse($filters['date_from']);
        $filterYear = $filterDate->year;
        $filterMonth = $filterDate->month;

        $query = MonthEndCountItem::query()
            ->from('month_end_count_items as meci')
            ->select(
                'meci.id',
                DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_name"),
                'sm.ItemCode as item_code',
                'sm.ItemDescription as item_description',
                'sm.BaseUOM as uom',
                'meci.total_qty as actual_inventory',
                'meci.current_soh as theoretical_inventory',
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

        $varianceDataRaw = $query->get();

        $varianceData = $varianceDataRaw->map(function ($item) {
            if ($item->cost === null) {
                return null;
            }

            $actualInventory = (float) $item->actual_inventory;
            $theoreticalInventory = (float) $item->theoretical_inventory;
            $cost = (float) $item->cost;

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
        })->filter(); // ->filter() will remove null values

        // Paginate the mapped data
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $perPage = $filters['per_page'];
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
            'varianceData' => $varianceData, // For totals calculation on frontend
            'paginatedData' => $paginatedData,
            'filters' => $filters,
            'stores' => $stores,
            'assignedStoreIds' => $assignedStoreIds,
        ]);
    }

    public function export(Request $request)
    {
        // Placeholder for export functionality
        return response()->json(['message' => 'Export functionality will be implemented']);
    }

    private function getAssignedStoreIds($user)
    {
        $user->load(['roles', 'store_branches']);

        if ($user->roles->contains('name', 'admin')) {
            return StoreBranch::where('is_active', true)->pluck('id')->toArray();
        }

        return $user->store_branches->pluck('id')->toArray();
    }
}