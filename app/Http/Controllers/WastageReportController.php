<?php

namespace App\Http\Controllers;

use App\Models\Wastage;
use App\Models\StoreBranch;
use App\Enums\WastageStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\WastageReportExport;
use App\Exports\WastageTopItemsExport;
use Carbon\Carbon;

class WastageReportController extends Controller
{
    private const TAB_DETAILS = 'details';
    private const TAB_TOP_ITEMS = 'top_items';

    /**
     * Allowed "Top N" values for the top waste items tab (0 = show all).
     */
    private const TOP_LIMITS = [0, 5, 10, 20, 50];

    /**
     * Tabs rendered on the wastage report page
     */
    private function tabs(): array
    {
        return [
            ['key' => self::TAB_DETAILS, 'label' => 'Wastage Details', 'enabled' => true],
            ['key' => self::TAB_TOP_ITEMS, 'label' => 'Top Waste Items per Month', 'enabled' => true],
        ];
    }

    /**
     * Collect and normalize the shared filter set
     */
    private function resolveFilters(Request $request): array
    {
        $filters = $request->only([
            'tab',
            'date_from',
            'date_to',
            'store_branch_id',
            'status',
            'search',
            'per_page',
            'sort_field',
            'sort_direction',
            'filter_wastage_no',
            'filter_store',
            'filter_item',
            'filter_status',
            'filter_reason',
            'top_limit',
            'top_sort',
        ]);

        // Tab
        $filters['tab'] = in_array($filters['tab'] ?? null, [self::TAB_DETAILS, self::TAB_TOP_ITEMS], true)
            ? $filters['tab']
            : self::TAB_DETAILS;

        // Set default values
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['status'] = $filters['status'] ?? 'approved_lvl2';
        $filters['per_page'] = $filters['per_page'] ?? 50;

        // Top waste items options
        $topLimit = (int) ($filters['top_limit'] ?? 10);
        $filters['top_limit'] = in_array($topLimit, self::TOP_LIMITS, true) ? $topLimit : 10;
        $filters['top_sort'] = in_array($filters['top_sort'] ?? null, ['total_amount', 'total_qty'], true)
            ? $filters['top_sort']
            : 'total_amount';

        return $filters;
    }

    /**
     * Store branch IDs the given user is assigned to
     */
    private function assignedStoreIds($user): array
    {
        return \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();
    }

    /**
     * Base wastage query with every report filter applied.
     * Callers add their own select/aggregation on top.
     */
    private function buildFilteredQuery(array $filters, array $assignedStoreIds)
    {
        $query = Wastage::query()
            ->leftJoin('store_branches', 'store_branches.id', '=', 'wastages.store_branch_id')
            ->leftJoin('sap_masterfiles', 'sap_masterfiles.id', '=', 'wastages.sap_masterfile_id')
            ->when(!empty($assignedStoreIds), function ($q) use ($assignedStoreIds) {
                $q->whereIn('wastages.store_branch_id', $assignedStoreIds);
            })
            ->when($filters['date_from'] ?? null, function ($q) use ($filters) {
                $q->whereDate('wastages.created_at', '>=', $filters['date_from']);
            })
            ->when($filters['date_to'] ?? null, function ($q) use ($filters) {
                $q->whereDate('wastages.created_at', '<=', $filters['date_to']);
            })
            ->when(!empty($filters['store_branch_id']), function ($q) use ($filters) {
                $q->where('wastages.store_branch_id', $filters['store_branch_id']);
            })
            ->when(!empty($filters['status']), function ($q) use ($filters) {
                if ($filters['status'] !== 'all') {
                    $q->where('wastages.wastage_status', $filters['status']);
                }
            })
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];
                $q->where(function ($query) use ($search) {
                    $query->where('wastages.wastage_no', 'like', '%' . $search . '%')
                          ->orWhere('store_branches.name', 'like', '%' . $search . '%')
                          ->orWhere('store_branches.branch_code', 'like', '%' . $search . '%')
                          ->orWhere('sap_masterfiles.ItemDescription', 'like', '%' . $search . '%')
                          ->orWhere('sap_masterfiles.ItemCode', 'like', '%' . $search . '%');
                });
            });

        // Apply column-specific filters
        if (!empty($filters['filter_wastage_no'])) {
            $query->where('wastages.wastage_no', 'like', "%{$filters['filter_wastage_no']}%");
        }
        if (!empty($filters['filter_store'])) {
            $query->where('store_branches.name', 'like', "%{$filters['filter_store']}%");
        }
        if (!empty($filters['filter_item'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('sap_masterfiles.ItemCode', 'like', "%{$filters['filter_item']}%")
                  ->orWhere('sap_masterfiles.ItemDescription', 'like', "%{$filters['filter_item']}%");
            });
        }
        if (!empty($filters['filter_status'])) {
            $query->where('wastages.wastage_status', 'like', "%{$filters['filter_status']}%");
        }
        if (!empty($filters['filter_reason'])) {
            $query->where('wastages.reason', 'like', "%{$filters['filter_reason']}%");
        }

        return $query;
    }

    /**
     * Summary totals across the whole filtered set (before pagination / top-N slicing)
     */
    private function summaryTotals(array $filters, array $assignedStoreIds): array
    {
        $query = $this->buildFilteredQuery($filters, $assignedStoreIds);

        return [
            'total_records' => (clone $query)->count(),
            'total_quantity' => (float) (clone $query)->sum('wastages.wastage_qty'),
            'total_cost' => (float) (clone $query)->sum(DB::raw('wastages.wastage_qty * wastages.cost')),
        ];
    }

    /**
     * Aggregate wastage per month + item, then keep the top N items of each month.
     *
     * Returns month sections ordered newest first, each holding its own totals
     * and the ranked item rows.
     */
    private function topItemsPerMonth(array $filters, array $assignedStoreIds): array
    {
        $sortColumn = ($filters['top_sort'] ?? 'total_amount') === 'total_qty'
            ? DB::raw('SUM(wastages.wastage_qty)')
            : DB::raw('SUM(wastages.wastage_qty * wastages.cost)');

        $rows = $this->buildFilteredQuery($filters, $assignedStoreIds)
            ->select([
                DB::raw('YEAR(wastages.created_at) as period_year'),
                DB::raw('MONTH(wastages.created_at) as period_month'),
                'wastages.sap_masterfile_id',
                DB::raw('MAX(sap_masterfiles.ItemCode) as item_code'),
                DB::raw('MAX(sap_masterfiles.ItemDescription) as item_description'),
                DB::raw('MAX(sap_masterfiles.BaseUOM) as uom'),
                DB::raw('SUM(wastages.wastage_qty) as total_qty'),
                DB::raw('SUM(wastages.wastage_qty * wastages.cost) as total_amount'),
                DB::raw('COUNT(*) as record_count'),
            ])
            ->groupBy(
                DB::raw('YEAR(wastages.created_at)'),
                DB::raw('MONTH(wastages.created_at)'),
                'wastages.sap_masterfile_id'
            )
            ->orderBy(DB::raw('YEAR(wastages.created_at)'), 'desc')
            ->orderBy(DB::raw('MONTH(wastages.created_at)'), 'desc')
            ->orderBy($sortColumn, 'desc')
            ->get();

        $topLimit = (int) ($filters['top_limit'] ?? 10);

        $months = [];

        foreach ($rows as $row) {
            $year = (int) $row->period_year;
            $month = (int) $row->period_month;
            $periodKey = sprintf('%04d-%02d', $year, $month);

            if (!isset($months[$periodKey])) {
                $months[$periodKey] = [
                    'period' => $periodKey,
                    'period_label' => Carbon::create($year, $month, 1)->format('F Y'),
                    'month_total_qty' => 0.0,
                    'month_total_amount' => 0.0,
                    'item_count' => 0,
                    'items' => [],
                ];
            }

            $totalQty = (float) $row->total_qty;
            $totalAmount = (float) $row->total_amount;

            $months[$periodKey]['month_total_qty'] += $totalQty;
            $months[$periodKey]['month_total_amount'] += $totalAmount;
            $months[$periodKey]['item_count']++;

            // Rows arrive pre-sorted per month, so the first N are the top N
            if ($topLimit === 0 || count($months[$periodKey]['items']) < $topLimit) {
                $months[$periodKey]['items'][] = [
                    'rank' => count($months[$periodKey]['items']) + 1,
                    'sap_masterfile_id' => $row->sap_masterfile_id,
                    'item_code' => $row->item_code ?: 'N/A',
                    'item_description' => $row->item_description ?: 'No description',
                    'uom' => $row->uom ?: 'N/A',
                    'total_qty' => $totalQty,
                    'total_amount' => $totalAmount,
                    'record_count' => (int) $row->record_count,
                ];
            }
        }

        // Share of the month total, computed once the month totals are complete
        foreach ($months as $key => $month) {
            $months[$key]['items'] = array_map(function ($item) use ($month) {
                $item['amount_share'] = $month['month_total_amount'] > 0
                    ? ($item['total_amount'] / $month['month_total_amount']) * 100
                    : 0;
                return $item;
            }, $month['items']);
        }

        return array_values($months);
    }

    /**
     * Display a listing of wastage records as a report
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $filters = $this->resolveFilters($request);
        $assignedStoreIds = $this->assignedStoreIds($user);

        $paginatedData = null;
        $topItems = [];

        if ($filters['tab'] === self::TAB_TOP_ITEMS) {
            $topItems = $this->topItemsPerMonth($filters, $assignedStoreIds);
        } else {
            $query = $this->buildFilteredQuery($filters, $assignedStoreIds)
                ->select('wastages.*')
                ->with(['storeBranch', 'sapMasterfile']);

            // Apply Sorting
            if (!empty($filters['sort_field'])) {
                $sortField = $filters['sort_field'];
                $sortDir = $filters['sort_direction'] ?? 'asc';

                $sortMap = [
                    'wastage_no' => 'wastages.wastage_no',
                    'store_name' => 'store_branches.name',
                    'item_description' => 'sap_masterfiles.ItemDescription',
                    'wastage_qty' => 'wastages.wastage_qty',
                    'cost' => 'wastages.cost',
                    'total_cost' => DB::raw('wastages.wastage_qty * wastages.cost'),
                    'wastage_status' => 'wastages.wastage_status',
                    'reason' => 'wastages.reason',
                    'created_at' => 'wastages.created_at',
                ];

                if (isset($sortMap[$sortField])) {
                    $query->orderBy($sortMap[$sortField], $sortDir);
                }
            } else {
                $query->orderBy('wastages.created_at', 'desc');
            }

            $paginatedData = $query->paginate($filters['per_page'])->withQueryString();
        }

        // Get store options for filtering
        $storeOptions = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get()
            ->map(function ($store) {
                return [
                    'value' => $store->id,
                    'label' => $store->name . ' (' . $store->branch_code . ')',
                ];
            });

        // Get status options
        $statusOptions = collect(WastageStatus::cases())->map(function ($status) {
            return [
                'value' => $status->value,
                'label' => $status->getLabel(),
            ];
        });
        $statusOptions->prepend(['value' => 'all', 'label' => 'All Statuses']);

        return Inertia::render('Reports/WastageReport/Index', [
            'wastages' => $paginatedData ? $paginatedData->items() : [],
            'paginatedData' => $paginatedData,
            'topItems' => $topItems,
            'tabs' => $this->tabs(),
            'filters' => $filters,
            'stores' => $storeOptions,
            'statusOptions' => $statusOptions,
            'assignedStoreIds' => $assignedStoreIds,
            'summaryTotals' => $this->summaryTotals($filters, $assignedStoreIds),
        ]);
    }

    /**
     * Export wastage report to Excel
     */
    public function export(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermissionTo('export wastage report')) {
            abort(403, 'You do not have permission to export wastage report');
        }

        $filters = [];

        try {
            $filters = $this->resolveFilters($request);
            $assignedStoreIds = $this->assignedStoreIds($user);

            if ($filters['tab'] === self::TAB_TOP_ITEMS) {
                return $this->exportTopItems($filters, $assignedStoreIds);
            }

            // Get all filtered wastage records
            $wastageRecords = $this->buildFilteredQuery($filters, $assignedStoreIds)
                ->select('wastages.*')
                ->with(['storeBranch', 'sapMasterfile'])
                ->orderBy('wastages.created_at', 'desc')
                ->get();

            // Transform the individual records for export
            $exportData = $wastageRecords->map(function ($item) {
                return [
                    'Wastage #' => $item->wastage_no,
                    'Store' => $item->storeBranch ? $item->storeBranch->name : 'N/A',
                    'Item Code' => $item->sapMasterfile ? $item->sapMasterfile->ItemCode : 'N/A',
                    'Item Description' => $item->sapMasterfile ? $item->sapMasterfile->ItemDescription : 'N/A',
                    'UoM' => $item->sapMasterfile ? $item->sapMasterfile->BaseUOM : 'N/A',
                    'Quantity' => $item->wastage_qty,
                    'Unit Cost' => $item->cost,
                    'Total Cost' => $item->wastage_qty * $item->cost,
                    'Status' => $item->wastage_status->getLabel(),
                    'Reason' => $item->reason,
                    'Remarks' => $item->remarks,
                    'Date' => $item->created_at->format('m/d/Y h:i A'),
                ];
            })->toArray();

            $export = new WastageReportExport($exportData);

            return Excel::download($export, 'wastage_report_' . now()->format('Y_m_d_His') . '.xlsx');

        } catch (\Exception $e) {
            \Log::error('Wastage report export failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'filters' => $filters
            ]);

            return back()->withErrors(['error' => 'Failed to export wastage report: ' . $e->getMessage()]);
        }
    }

    /**
     * Export the top waste items per month tab
     */
    private function exportTopItems(array $filters, array $assignedStoreIds)
    {
        $months = $this->topItemsPerMonth($filters, $assignedStoreIds);

        $exportData = [];

        foreach ($months as $month) {
            foreach ($month['items'] as $item) {
                $exportData[] = [
                    'Month' => $month['period_label'],
                    'Rank' => $item['rank'],
                    'Item Code' => $item['item_code'],
                    'Item Description' => $item['item_description'],
                    'UoM' => $item['uom'],
                    'Total Qty' => $item['total_qty'],
                    'Total Amount' => $item['total_amount'],
                    '% of Month' => round($item['amount_share'], 2),
                    'Records' => $item['record_count'],
                ];
            }
        }

        $export = new WastageTopItemsExport($exportData, [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'top_limit' => $filters['top_limit'],
        ]);

        return Excel::download($export, 'top_waste_items_per_month_' . now()->format('Y_m_d_His') . '.xlsx');
    }
}
