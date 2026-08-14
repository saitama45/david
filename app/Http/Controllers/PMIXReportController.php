<?php

namespace App\Http\Controllers;

use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\StoreBranch;
use App\Models\POSMasterfile;
use App\Exports\PMIXReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class PMIXReportController extends Controller
{
    /** Sortable per-store metric suffix => key inside the `stores` cell. */
    private const SORT_KEYS = [
        'qty' => 'quantity',
        'sales' => 'sales',
        'takeout' => 'take_out',
        'dinein' => 'dine_in',
    ];

    /**
     * Aggregate transactions into the PMIX matrix: one row per POS code, one
     * cell per store.
     *
     * Take out / dine in are reported as quantities so that for every store
     * take_out + dine_in == quantity. Lines imported before the Sales Report
     * gained its "Take Out" column default to dine in.
     *
     * @return array{0: array, 1: array} [$pmixData keyed by POS code, $storeColumns]
     */
    private function buildPmixData($transactions): array
    {
        $pmixData = [];
        $storeColumns = [];

        foreach ($transactions as $transaction) {
            $storeKey = $transaction->store_branch->name . ' (' . $transaction->store_branch->brand_code . ')';
            $storeColumns[$transaction->store_branch_id] = $storeKey;

            foreach ($transaction->store_transaction_items as $item) {
                if ($item->posMasterfile) {
                    $posKey = $item->posMasterfile->POSCode;

                    if (!isset($pmixData[$posKey])) {
                        $pmixData[$posKey] = [
                            'POSCode' => $item->posMasterfile->POSCode,
                            'POSDescription' => $item->posMasterfile->POSDescription,
                            'Category' => $item->posMasterfile->Category,
                            'SubCategory' => $item->posMasterfile->SubCategory,
                            'stores' => []
                        ];
                    }

                    if (!isset($pmixData[$posKey]['stores'][$transaction->store_branch_id])) {
                        $pmixData[$posKey]['stores'][$transaction->store_branch_id] = [
                            'quantity' => 0,
                            'sales' => 0,
                            'take_out' => 0,
                            'dine_in' => 0
                        ];
                    }

                    $pmixData[$posKey]['stores'][$transaction->store_branch_id]['quantity'] += $item->quantity;
                    $pmixData[$posKey]['stores'][$transaction->store_branch_id]['sales'] += $item->net_total;

                    $classification = $item->take_out ? 'take_out' : 'dine_in';
                    $pmixData[$posKey]['stores'][$transaction->store_branch_id][$classification] += $item->quantity;
                }
            }
        }

        return [$pmixData, $storeColumns];
    }

    /**
     * Display the PMIX Report page.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Get filter parameters
        $filters = $request->only([
            'date_from',
            'date_to',
            'store_ids',
            'search',
            'per_page',
            'pos_code',
            'description',
            'category',
            'sub_category',
            'sort_field',
            'sort_direction'
        ]);

        // Set default values
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['per_page'] = $filters['per_page'] ?? 50;
        $filters['store_ids'] = $filters['store_ids'] ?? [];

        // Get user's assigned stores
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        // Get all stores for filter dropdowns (user can only see their assigned stores)
        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get(['id', 'name', 'brand_code']);

        // If no specific stores selected, default to all assigned stores
        if (empty($filters['store_ids'])) {
            $filters['store_ids'] = $assignedStoreIds->toArray();
        }

        // Filter stores to only include assigned stores
        $filters['store_ids'] = array_intersect($filters['store_ids'], $assignedStoreIds->toArray());

        // Build base query for store transactions with items
        $query = StoreTransaction::with([
            'store_branch' => fn($q) => $q->select('id', 'name', 'brand_code'),
            'store_transaction_items.posMasterfile' => fn($q) => $q->select('id', 'POSCode', 'POSDescription', 'Category', 'SubCategory')
        ])
        ->whereHas('store_transaction_items.posMasterfile')
        ->whereBetween('order_date', [$filters['date_from'], $filters['date_to']]);

        // Apply user permissions - filter to only show transactions from assigned stores
        if ($assignedStoreIds->isNotEmpty()) {
            $query->whereIn('store_branch_id', $assignedStoreIds);
        }

        // Apply store filter
        if (!empty($filters['store_ids'])) {
            $query->whereIn('store_branch_id', $filters['store_ids']);
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('store_transaction_items.posMasterfile', function($q) use ($search) {
                $q->where('POSCode', 'like', "%{$search}%")
                  ->orWhere('POSDescription', 'like', "%{$search}%")
                  ->orWhere('Category', 'like', "%{$search}%")
                  ->orWhere('SubCategory', 'like', "%{$search}%");
            });
        }

        // Get all transactions for the date range and filters
        $transactions = $query->orderBy('order_date', 'desc')->get();

        // Build PMIX data structure
        [$pmixData, $storeColumns] = $this->buildPmixData($transactions);

        // Convert to array for filtering and sorting
        $pmixDataArray = array_values($pmixData);

        // Apply column-specific filters
        if (!empty($filters['pos_code'])) {
            $pmixDataArray = array_filter($pmixDataArray, fn($item) => stripos($item['POSCode'], $filters['pos_code']) !== false);
        }
        if (!empty($filters['description'])) {
            $pmixDataArray = array_filter($pmixDataArray, fn($item) => stripos($item['POSDescription'], $filters['description']) !== false);
        }
        if (!empty($filters['category'])) {
            $pmixDataArray = array_filter($pmixDataArray, fn($item) => stripos($item['Category'], $filters['category']) !== false);
        }
        if (!empty($filters['sub_category'])) {
            $pmixDataArray = array_filter($pmixDataArray, fn($item) => stripos($item['SubCategory'], $filters['sub_category']) !== false);
        }

        // Apply sorting
        if (!empty($filters['sort_field'])) {
            $field = $filters['sort_field'];
            $dir = ($filters['sort_direction'] ?? 'asc') === 'desc' ? -1 : 1;

            usort($pmixDataArray, function($a, $b) use ($field, $dir) {
                $valA = 0;
                $valB = 0;

                if (in_array($field, ['POSCode', 'POSDescription', 'Category', 'SubCategory'])) {
                    $valA = strtolower($a[$field]);
                    $valB = strtolower($b[$field]);
                } elseif (str_starts_with($field, 'total_')) {
                    $key = self::SORT_KEYS[substr($field, strlen('total_'))] ?? null;

                    if ($key) {
                        $valA = array_sum(array_column($a['stores'], $key));
                        $valB = array_sum(array_column($b['stores'], $key));
                    }
                } elseif (str_starts_with($field, 'store_')) {
                    // store_{id}_{qty|sales|takeout|dinein}
                    $parts = explode('_', $field, 3);
                    $storeId = $parts[1];
                    $key = self::SORT_KEYS[$parts[2] ?? ''] ?? null;

                    if ($key) {
                        $valA = $a['stores'][$storeId][$key] ?? 0;
                        $valB = $b['stores'][$storeId][$key] ?? 0;
                    }
                }

                if ($valA == $valB) return 0;
                return ($valA < $valB ? -1 : 1) * $dir;
            });
        } else {
            // Default sort by POS Code if no sort field provided
            usort($pmixDataArray, fn($a, $b) => strcmp($a['POSCode'], $b['POSCode']));
        }

        // Create pagination-like structure
        $currentPage = request()->get('page', 1);
        $offset = ($currentPage - 1) * $filters['per_page'];
        $totalItems = count($pmixDataArray);
        $itemsForPage = array_slice($pmixDataArray, $offset, $filters['per_page']);

        $paginatedData = new \Illuminate\Pagination\LengthAwarePaginator(
            $itemsForPage,
            $totalItems,
            $filters['per_page'],
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
        $paginatedData->appends($request->all());

        return Inertia::render('Reports/PMIXReport/Index', [
            'pmixData' => array_values($itemsForPage),
            'paginatedData' => $paginatedData,
            'filters' => $filters,
            'stores' => $stores,
            'storeColumns' => $storeColumns,
            'assignedStoreIds' => $assignedStoreIds
        ]);
    }

    /**
     * Export PMIX Report to Excel.
     */
    public function export(Request $request)
    {
        // Get the same filtered data as index method
        $user = Auth::user();

        $filters = $request->only([
            'date_from',
            'date_to',
            'store_ids',
            'search'
        ]);

        // Set defaults for export
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['store_ids'] = $filters['store_ids'] ?? [];

        // Get user's assigned stores
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        // If no specific stores selected, default to all assigned stores
        if (empty($filters['store_ids'])) {
            $filters['store_ids'] = $assignedStoreIds->toArray();
        }

        // Filter stores to only include assigned stores
        $filters['store_ids'] = array_intersect($filters['store_ids'], $assignedStoreIds->toArray());

        // Build same query as index
        $query = StoreTransaction::with([
            'store_branch',
            'store_transaction_items.posMasterfile'
        ])
        ->whereHas('store_transaction_items.posMasterfile')
        ->whereBetween('order_date', [$filters['date_from'], $filters['date_to']]);

        // Apply user permissions
        if ($assignedStoreIds->isNotEmpty()) {
            $query->whereIn('store_branch_id', $assignedStoreIds);
        }

        // Apply store filter
        if (!empty($filters['store_ids'])) {
            $query->whereIn('store_branch_id', $filters['store_ids']);
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('store_transaction_items.posMasterfile', function($q) use ($search) {
                $q->where('POSCode', 'like', "%{$search}%")
                  ->orWhere('POSDescription', 'like', "%{$search}%")
                  ->orWhere('Category', 'like', "%{$search}%")
                  ->orWhere('SubCategory', 'like', "%{$search}%");
            });
        }

        // Get all data for export
        $transactions = $query->orderBy('order_date', 'desc')->get();

        // Build PMIX data structure for export
        [$pmixData, $storeColumns] = $this->buildPmixData($transactions);

        // Sort the PMIX data by POS Code
        ksort($pmixData);

        // Export to Excel using PMIXReportExport class
        return Excel::download(
            new PMIXReportExport($pmixData, $storeColumns, $filters),
            'pmix-report-' . Carbon::now()->format('Y-m-d') . '.xlsx'
        );
    }
}