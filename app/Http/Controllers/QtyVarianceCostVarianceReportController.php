<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StoreBranch;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Services\MonthEndStockVariance;
use Inertia\Inertia;
use Illuminate\Support\Collection;
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

        $mecDates = $this->mecDateOptions($activeAssignedStoreIds);
        $defaultMecDate = $this->defaultMecDate($mecDates);

        $filters = $this->filtersFromRequest($request, $activeAssignedStoreIds, $mecDates, $defaultMecDate);
        $filters['per_page'] = $request->get('per_page', 50);

        $varianceData = $this->varianceRows($filters);

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
            'mecDates' => $mecDates->map(fn (array $option) => [
                'value' => $option['value'],
                'label' => $option['label'],
                'disabled' => ! $option['approved'],
            ])->values(),
            'defaultMecDate' => $defaultMecDate,
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

        $mecDates = $this->mecDateOptions($activeAssignedStoreIds);
        $filters = $this->filtersFromRequest($request, $activeAssignedStoreIds, $mecDates, $this->defaultMecDate($mecDates));

        $varianceData = $this->varianceRows($filters);

        $filename = 'qty_variance_cost_variance_report_' . Carbon::now('Asia/Manila')->format('Ymd_His') . '.xlsx';

        return Excel::download(new QtyVarianceCostVarianceReportExport($varianceData->toArray()), $filename);
    }

    public function getBreakdown($id)
    {
        $assignedStoreIds = $this->getAssignedStoreIds(Auth::user());

        $meci = MonthEndCountItem::with('schedule')
            ->whereIn('branch_id', $assignedStoreIds)
            ->findOrFail($id);

        // The report row is the branch + item group, not this single line: an
        // item registered under two BaseUOMs is counted twice and adjusted once.
        $group = MonthEndCountItem::where('month_end_schedule_id', $meci->month_end_schedule_id)
            ->where('branch_id', $meci->branch_id)->where('item_code', $meci->item_code)
            ->whereNotNull('level2_approved_at')
            ->selectRaw('SUM(total_qty) as counted, MAX(level2_approved_at) as approved_at')
            ->first();

        $breakdown = app(MonthEndStockVariance::class)->breakdown(
            (int) $meci->month_end_schedule_id, (int) $meci->branch_id, (string) $meci->item_code,
            $group->counted, $group->approved_at ? (string) $group->approved_at : null
        );

        return response()->json(array_merge(['actual_mec' => (float) $group->counted], $breakdown));
    }

    /**
     * The MEC Scheduled Dates set up in /month-end-schedules, newest first -
     * the only dates the report's date filter offers.
     *
     * The report used to take a free date and read its calendar month, but a
     * count is often scheduled in the following month (the March count on
     * April 5), so the picked date and the count shown did not match. A count
     * scheduled in the future has no result yet and is not offered.
     *
     * The report only holds level 2 approved counts, so a date whose count has
     * none for the user's stores would open an empty report. It stays in the
     * list, but cannot be picked and says why.
     *
     * @param  array<int, int>  $storeIds  the user's active assigned stores
     * @return Collection<int, array{value:string,label:string,approved:bool}>
     */
    private function mecDateOptions(array $storeIds): Collection
    {
        $schedules = MonthEndSchedule::query()
            ->whereDate('calculated_date', '<=', Carbon::today('Asia/Manila')->toDateString())
            ->orderByDesc('calculated_date')
            ->get(['id', 'year', 'month', 'calculated_date']);

        $progress = MonthEndCountItem::query()
            ->whereIn('month_end_schedule_id', $schedules->pluck('id'))
            ->whereIn('branch_id', $storeIds)
            ->groupBy('month_end_schedule_id')
            ->selectRaw('month_end_schedule_id, COUNT(*) as lines, SUM(CASE WHEN level2_approved_at IS NOT NULL THEN 1 ELSE 0 END) as approved')
            ->toBase()
            ->get()
            ->keyBy('month_end_schedule_id');

        return $schedules->map(function (MonthEndSchedule $schedule) use ($progress) {
            $lines = (int) ($progress->get($schedule->id)->lines ?? 0);
            $approved = (int) ($progress->get($schedule->id)->approved ?? 0) > 0;

            return [
                'value' => $schedule->calculated_date->format('Y-m-d'),
                // The count's own month, because the date alone can sit in the next one.
                'label' => $schedule->calculated_date->format('M j, Y').' - '
                    .Carbon::create($schedule->year, $schedule->month, 1)->format('F Y').' count'
                    .($approved ? '' : ($lines > 0 ? ' (awaiting Level 2 approval)' : ' (no count uploaded)')),
                'approved' => $approved,
            ];
        })->sortByDesc('approved')->unique('value')->sortByDesc('value')->values();
    }

    /** The latest count with approved results; null when no count has any yet. */
    private function defaultMecDate(Collection $mecDates): ?string
    {
        return $mecDates->firstWhere('approved', true)['value'] ?? null;
    }

    private function filtersFromRequest(Request $request, array $activeAssignedStoreIds, Collection $mecDates, ?string $defaultMecDate): array
    {
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

        // Only a MEC Scheduled Date with approved results is valid; anything else falls back to the default.
        $mecDate = $mecDates->where('approved', true)->pluck('value')->contains($request->get('mec_date'))
            ? $request->get('mec_date')
            : $defaultMecDate;

        return [
            'mec_date' => $mecDate,
            'store_ids' => $storeIdsFilter,
            'search' => $request->get('search', ''),
            'sort_field' => $request->get('sort_field', ''),
            'sort_direction' => $request->get('sort_direction', 'asc'),
            'filter_store' => $request->get('filter_store', ''),
            'filter_item_code' => $request->get('filter_item_code', ''),
            'filter_item_description' => $request->get('filter_item_description', ''),
            'filter_uom' => $request->get('filter_uom', ''),
        ];
    }

    /** The filtered, computed and sorted report rows, shared by the page and the export. */
    private function varianceRows(array $filters): Collection
    {
        $query = $this->varianceQuery($filters);

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

        $varianceData = $query->get()->map(function ($item) {
            $cost = (float) ($item->cost ?? 0);
            $actualInventory = (float) $item->actual_inventory;
            $theoreticalInventory = (float) $item->theoretical_inventory;

            $qtyVariance = $actualInventory - $theoreticalInventory;
            $actualCost = $cost * $actualInventory;
            $theoreticalCost = $cost * $theoreticalInventory;
            $costVariance = $actualCost - $theoreticalCost;

            return [
                'id' => $item->id,
                'mec_date' => Carbon::parse($item->mec_date)->format('Y-m-d'),
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

            return $varianceData->sortBy($sortField, SORT_REGULAR, $sortDir);
        }

        // Default sort by store name
        return $varianceData->sortBy('store_name');
    }

    /**
     * One row per count + branch + item code, the grain the month end count is
     * approved and posted at. Theoretical inventory is the stock on hand the
     * count reconciled - see MonthEndStockVariance.
     *
     * The count reported is the one scheduled on the selected MEC Scheduled Date.
     */
    private function varianceQuery(array $filters)
    {
        $variance = app(MonthEndStockVariance::class);
        $scheduleIds = $filters['mec_date']
            ? MonthEndSchedule::whereDate('calculated_date', $filters['mec_date'])->pluck('id')->all()
            : [];

        return MonthEndCountItem::query()
            ->from('month_end_count_items as meci')
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('sap_masterfiles as sm', 'meci.sap_masterfile_id', '=', 'sm.id')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->leftJoinSub($variance->adjustments($scheduleIds), 'adj', function ($join) {
                $join->on('adj.store_branch_id', '=', 'meci.branch_id')
                    ->on('adj.item_code', '=', 'meci.item_code')
                    ->on('adj.month_end_schedule_id', '=', 'meci.month_end_schedule_id');
            })
            // The count posts to one base-stock row, which names the UOM it was
            // reconciled in; without a posting the counted line names it.
            ->leftJoin('sap_masterfiles as adjsm', 'adjsm.id', '=', 'adj.product_inventory_id')
            ->leftJoinSub($variance->costs(), 'cost', 'cost.item_code', '=', 'meci.item_code')
            ->whereNotNull('meci.level2_approved_at')
            ->whereIn('meci.branch_id', $filters['store_ids'])
            ->whereIn('meci.month_end_schedule_id', $scheduleIds)
            ->groupBy('meci.month_end_schedule_id', 'mes.calculated_date', 'meci.branch_id', 'meci.item_code',
                'sb.name', 'sb.branch_code', 'adj.adjustment', 'adjsm.BaseUOM', 'cost.cost')
            ->select(
                DB::raw('MIN(meci.id) as id'),
                'mes.calculated_date as mec_date',
                DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_name"),
                'meci.item_code as item_code',
                DB::raw('MAX(sm.ItemDescription) as item_description'),
                DB::raw('COALESCE(adjsm.BaseUOM, MAX(sm.BaseUOM)) as uom'),
                DB::raw('SUM(meci.total_qty) as actual_inventory'),
                DB::raw('SUM(meci.total_qty) - COALESCE(adj.adjustment, 0) as theoretical_inventory'),
                DB::raw('cost.cost as cost')
            );
    }

    private function getAssignedStoreIds($user)
    {
        return \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();
    }
}
