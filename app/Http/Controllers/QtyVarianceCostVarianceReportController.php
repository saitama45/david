<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StoreBranch;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Services\MonthEndStockVariance;
use Inertia\Inertia;
use Illuminate\Support\Arr;
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
            'period' => $this->periodFor($filters['mec_date']),
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

        // The report row is the branch + item group, not this single line: an item
        // counted in two units is one row, keyed by the ItemCode of its SAP item.
        $itemCode = DB::table('sap_masterfiles')->where('id', $meci->sap_masterfile_id)->value('ItemCode') ?? $meci->item_code;
        $variance = app(MonthEndStockVariance::class);
        $row = $variance->rows($meci->schedule, [(int) $meci->branch_id], (string) $itemCode)->first();

        abort_if($row === null, 404);

        [$from, $to] = $variance->period($meci->schedule);

        return response()->json([
            'actual_mec' => $row['actual_inventory'],
            'theoretical' => $row['theoretical_inventory'],
            'uom' => $row['uom'],
            'period_from' => Carbon::parse($from)->format('M j, Y'),
            'period_to' => Carbon::parse($to)->format('M j, Y'),
        ] + $row['breakdown']);
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

    /**
     * The filtered and sorted report rows, shared by the page and the export: one row per
     * branch + item code of the count scheduled on the selected MEC Scheduled Date, with the
     * Inventory Movement Report's figures for it - see MonthEndStockVariance.
     */
    private function varianceRows(array $filters): Collection
    {
        $variance = app(MonthEndStockVariance::class);

        $varianceData = $this->schedules($filters['mec_date'])
            ->flatMap(fn (MonthEndSchedule $schedule) => $variance->rows($schedule, $filters['store_ids']))
            ->map(fn (array $row) => Arr::except($row, ['branch_id', 'breakdown']));

        // The rows are computed, so the search and column filters are matched here, not in SQL.
        $contains = fn ($value, $needle) => mb_stripos((string) $value, (string) $needle) !== false;

        if (filled($filters['search'])) {
            $varianceData = $varianceData->filter(fn (array $row) => $contains($row['item_code'], $filters['search'])
                || $contains($row['item_description'], $filters['search'])
                || $contains($row['store_name'], $filters['search']));
        }

        foreach (['filter_store' => 'store_name', 'filter_item_code' => 'item_code',
            'filter_item_description' => 'item_description', 'filter_uom' => 'uom'] as $filter => $field) {
            if (filled($filters[$filter])) {
                $varianceData = $varianceData->filter(fn (array $row) => $contains($row[$field], $filters[$filter]));
            }
        }

        // Apply Sorting to the collection
        if ($filters['sort_field']) {
            $sortField = $filters['sort_field'];
            $sortDir = $filters['sort_direction'] === 'desc';

            return $varianceData->sortBy($sortField, SORT_REGULAR, $sortDir);
        }

        // Default sort by store name, then item code
        return $varianceData->sortBy([['store_name', 'asc'], ['item_code', 'asc']]);
    }

    /** The counts scheduled on the selected MEC Scheduled Date - normally one. */
    private function schedules(?string $mecDate): Collection
    {
        return $mecDate
            ? MonthEndSchedule::whereDate('calculated_date', $mecDate)->orderBy('id')->get()
            : collect();
    }

    /**
     * The dates the selected count's movements cover, to show on the page: the same range
     * gives the same figures in the Inventory Movement Report.
     *
     * @return array{from: string, to: string}|null
     */
    private function periodFor(?string $mecDate): ?array
    {
        $schedule = $this->schedules($mecDate)->first();

        if (! $schedule) {
            return null;
        }

        [$from, $to] = app(MonthEndStockVariance::class)->period($schedule);

        return ['from' => Carbon::parse($from)->format('M j, Y'), 'to' => Carbon::parse($to)->format('M j, Y')];
    }

    private function getAssignedStoreIds($user)
    {
        return \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();
    }
}
