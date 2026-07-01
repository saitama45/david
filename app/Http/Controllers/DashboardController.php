<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Enum\UserRole;
use App\Http\Services\AdoptionRateTrackingService;
use App\Mail\OneTimePasswordMail;
use App\Models\Branch;
// use App\Models\ProductInventory; // This model is now explicitly NOT used for stock/order items
use App\Models\ProductInventoryStock; // This model is now linked to SAPMasterfile
use App\Models\ProductInventoryStockManager; // This model is now linked to SAPMasterfile
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\POSMasterfile;
use App\Models\SupplierItems; // Import SupplierItems model
use App\Models\User;
use App\Models\SAPMasterfile; // Ensure SAPMasterfile is imported
use App\Models\SalesBudget;
use App\Models\Wastage;
use App\Enums\WastageStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log; // Import Log facade for error logging

class DashboardController extends Controller
{
    public function __construct(private AdoptionRateTrackingService $adoptionRateService)
    {
    }

    public function index()
    {
        $timePeriods = TimePeriod::values();
        $time_period = (int)(request('time_period') ?? 0);

        $inventory_type = request('inventory_type') ?? 'quantity';

        // Get branches as a clean array for the frontend MultiSelect
        $branchesOptions = StoreBranch::options()->toArray();

        $branch = request('branch');

        $branchIds = $this->resolveDashboardBranchIds($branch, $branchesOptions);

        $chart_time_period = request('chart_time_period') ?? 0;

        // Cache dashboard data per user + filter combination (10 minutes TTL)
        $cacheKey = 'dashboard_v8_' . auth()->id() . '_' . md5(json_encode([
            $branchIds, $time_period, $chart_time_period, $inventory_type
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($branchIds, $time_period, $chart_time_period, $inventory_type) {
            $inventories = $this->getInventories($branchIds, $time_period);
            $upcomingInventories = $this->getUpcomingInventories($branchIds, $time_period);
            $accountPayable = $this->getAccountPayable($branchIds, $time_period);

            $salesValue = $this->calculateTotalSalesForBranches($branchIds, $time_period, Carbon::today()->year);
            $sales = number_format($salesValue, 2, '.', ',');

            $cogs = $this->getCogs($branchIds, $time_period);
            $begginingInventory = $this->getBeginningInventory($branchIds);
            $endingInventory = $this->getEndingInventory($branchIds);
            $cogsAll = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
                ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));
            $averageInventory = ($begginingInventory + $endingInventory) / 2;
            $dio = $this->getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period);
            $productInventoryStock = $this->getTop10Products($branchIds, $inventory_type);
            $dpo = $this->getDaysPayableOutstanding($branchIds, $cogsAll, $chart_time_period);
            $salesChartData = $this->getSalesBudgetChartData($branchIds, $time_period);
            $wastageChartData = $this->getWastageChartData($branchIds, $time_period);

            // Calculate KPI metrics
            $currentYear = Carbon::today()->year;
            $previousYear = $currentYear - 1;
            $actualSales = $salesValue;
            $budgetSales = $this->calculateTotalSalesForBranches($branchIds, $time_period, $currentYear, true);
            $lastYearSales = $this->calculateTotalSalesForBranches($branchIds, $time_period, $previousYear);

            $achievement = $budgetSales > 0 ? ($actualSales / $budgetSales) * 100 : 0;
            $growth = $lastYearSales > 0 ? (($actualSales - $lastYearSales) / $lastYearSales) * 100 : 0;

            // Transaction Volume & ATV
            $transactionQuery = StoreTransaction::whereIn('store_branch_id', $branchIds)
                ->whereYear('order_date', $currentYear);
            
            $currentMonth = Carbon::today()->month;
            if ($time_period == 0) {
                $transactionQuery->whereMonth('order_date', '<=', $currentMonth);
            } else {
                $transactionQuery->whereMonth('order_date', $time_period);
            }
            
            $transactionCount = $transactionQuery->count();
            $atv = $transactionCount > 0 ? $actualSales / $transactionCount : 0;

            return [
                'inventories'        => $inventories,
                'upcomingInventories' => $upcomingInventories,
                'accountPayable'     => $accountPayable,
                'sales'              => $sales,
                'achievement'        => number_format($achievement, 1) . '%',
                'growth'             => number_format($growth, 1) . '%',
                'transactionCount'   => number_format($transactionCount, 0),
                'atv'                => number_format($atv, 2, '.', ','),
                'cogs'               => $cogs,
                'dio'                => $dio,
                'dpo'                => $dpo,
                'top_10'             => $productInventoryStock,
                'salesChartData'     => $salesChartData,
                'wastageChartData'   => $wastageChartData,
            ];
        });

        return Inertia::render('Dashboard/Index', [
            'timePeriods' => $timePeriods,
            'branches'    => $branchesOptions,
            'filters'     => request()->only(['branch', 'time_period', 'chart_time_period', 'inventory_type']),
            ...$data,
        ]);
    }

    public function salesMixSubcategories(Request $request)
    {
        $validated = $request->validate([
            'branch' => ['nullable'],
            'branch.*' => ['nullable'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $branchesOptions = StoreBranch::options()->toArray();
        $branchIds = $this->resolveDashboardBranchIds($request->input('branch'), $branchesOptions);
        $dateFrom = Carbon::parse($validated['date_from'] ?? Carbon::today()->startOfMonth())->format('Y-m-d');
        $dateTo = Carbon::parse($validated['date_to'] ?? Carbon::today())->format('Y-m-d');
        $subcategoryExpression = "COALESCE(NULLIF(TRIM(pos_masterfiles.SubCategory), ''), 'Uncategorized')";

        $subcategories = POSMasterfile::query()
            ->selectRaw("{$subcategoryExpression} as sub_category")
            ->groupBy(DB::raw($subcategoryExpression))
            ->pluck('sub_category')
            ->map(fn($subcategory) => $subcategory ?: 'Uncategorized');

        $revenueBySubcategory = StoreTransactionItem::query()
            ->join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
            ->join('pos_masterfiles', 'store_transaction_items.product_id', '=', 'pos_masterfiles.id')
            ->whereIn('store_transactions.store_branch_id', $branchIds)
            ->whereBetween('store_transactions.order_date', [$dateFrom, $dateTo])
            ->selectRaw("{$subcategoryExpression} as sub_category")
            ->selectRaw('SUM(store_transaction_items.net_total) as revenue')
            ->groupBy(DB::raw($subcategoryExpression))
            ->pluck('revenue', 'sub_category')
            ->mapWithKeys(fn($revenue, $subcategory) => [$subcategory ?: 'Uncategorized' => (float)$revenue]);

        $totalRevenue = (float)$revenueBySubcategory->sum();

        $rows = $subcategories
            ->unique()
            ->map(function ($subcategory) use ($revenueBySubcategory, $totalRevenue) {
                $revenue = (float)($revenueBySubcategory->get($subcategory, 0));

                return [
                    'sub_category' => $subcategory,
                    'revenue' => round($revenue, 2),
                    'revenue_percent' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 2) : 0,
                ];
            })
            ->sort(function ($a, $b) {
                if ($a['revenue'] === $b['revenue']) {
                    return strcasecmp($a['sub_category'], $b['sub_category']);
                }

                return $a['revenue'] < $b['revenue'] ? 1 : -1;
            })
            ->values()
            ->map(function ($row, $index) {
                $row['rank'] = $index + 1;

                return $row;
            });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total_revenue' => round($totalRevenue, 2),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'store_count' => count($branchIds),
            ],
        ]);
    }

    public function salesMixProductsByRevenue(Request $request)
    {
        $validated = $request->validate([
            'branch' => ['nullable'],
            'branch.*' => ['nullable'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $branchesOptions = StoreBranch::options()->toArray();
        $branchIds = $this->resolveDashboardBranchIds($request->input('branch'), $branchesOptions);
        $dateFrom = Carbon::parse($validated['date_from'] ?? Carbon::today()->startOfMonth())->format('Y-m-d');
        $dateTo = Carbon::parse($validated['date_to'] ?? Carbon::today())->format('Y-m-d');

        $products = POSMasterfile::query()
            ->select(['id', 'POSCode', 'POSDescription', 'SubCategory'])
            ->get();

        $revenueByProduct = StoreTransactionItem::query()
            ->join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
            ->whereIn('store_transactions.store_branch_id', $branchIds)
            ->whereBetween('store_transactions.order_date', [$dateFrom, $dateTo])
            ->groupBy('store_transaction_items.product_id')
            ->selectRaw('store_transaction_items.product_id, SUM(store_transaction_items.net_total) as revenue')
            ->pluck('revenue', 'product_id')
            ->map(fn($revenue) => (float)$revenue);

        $rows = $products
            ->map(function ($product) use ($revenueByProduct) {
                $posCode = (string)$product->POSCode;
                $subCategory = trim((string)$product->SubCategory);

                return [
                    'pos_code' => $posCode,
                    'item_name' => $product->POSDescription ?: $posCode,
                    'revenue' => round((float)$revenueByProduct->get($product->id, 0), 2),
                    'category' => $this->deriveSalesMixCategory($posCode),
                    'sub_category' => $subCategory !== '' ? $subCategory : 'Uncategorized',
                ];
            });

        $overall = $this->rankSalesMixMetricRows($this->sortSalesMixMetricRows($rows, 'revenue'), 'revenue');
        $categoryNames = ['Kitchen', 'Beverages & Others', 'Bakery'];
        $byCategory = collect($categoryNames)
            ->mapWithKeys(function ($category) use ($rows) {
                $categoryRows = $rows->where('category', $category)->values();

                return [
                    $category => $this->rankSalesMixMetricRows(
                        $this->sortSalesMixMetricRows($categoryRows, 'revenue'),
                        'revenue'
                    )->values(),
                ];
            });

        return response()->json([
            'overall' => $overall,
            'by_category' => $byCategory,
            'meta' => [
                'total_revenue' => round((float)$revenueByProduct->sum(), 2),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'store_count' => count($branchIds),
            ],
        ]);
    }

    public function salesMixProductsByQuantity(Request $request)
    {
        $validated = $request->validate([
            'branch' => ['nullable'],
            'branch.*' => ['nullable'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $branchesOptions = StoreBranch::options()->toArray();
        $branchIds = $this->resolveDashboardBranchIds($request->input('branch'), $branchesOptions);
        $dateFrom = Carbon::parse($validated['date_from'] ?? Carbon::today()->startOfMonth())->format('Y-m-d');
        $dateTo = Carbon::parse($validated['date_to'] ?? Carbon::today())->format('Y-m-d');

        $products = POSMasterfile::query()
            ->select(['id', 'POSCode', 'POSDescription', 'SubCategory'])
            ->get();

        $quantityByProduct = StoreTransactionItem::query()
            ->join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
            ->whereIn('store_transactions.store_branch_id', $branchIds)
            ->whereBetween('store_transactions.order_date', [$dateFrom, $dateTo])
            ->groupBy('store_transaction_items.product_id')
            ->selectRaw('store_transaction_items.product_id, SUM(store_transaction_items.quantity) as quantity')
            ->pluck('quantity', 'product_id')
            ->map(fn($quantity) => (float)$quantity);

        $rows = $products
            ->map(function ($product) use ($quantityByProduct) {
                $posCode = (string)$product->POSCode;
                $subCategory = trim((string)$product->SubCategory);

                return [
                    'pos_code' => $posCode,
                    'item_name' => $product->POSDescription ?: $posCode,
                    'quantity' => round((float)$quantityByProduct->get($product->id, 0), 2),
                    'category' => $this->deriveSalesMixCategory($posCode),
                    'sub_category' => $subCategory !== '' ? $subCategory : 'Uncategorized',
                ];
            });

        $overall = $this->rankSalesMixMetricRows($this->sortSalesMixMetricRows($rows, 'quantity'), 'quantity');
        $categoryNames = ['Kitchen', 'Beverages & Others', 'Bakery'];
        $byCategory = collect($categoryNames)
            ->mapWithKeys(function ($category) use ($rows) {
                $categoryRows = $rows->where('category', $category)->values();

                return [
                    $category => $this->rankSalesMixMetricRows(
                        $this->sortSalesMixMetricRows($categoryRows, 'quantity'),
                        'quantity'
                    )->values(),
                ];
            });

        return response()->json([
            'overall' => $overall,
            'by_category' => $byCategory,
            'meta' => [
                'total_quantity' => round((float)$quantityByProduct->sum(), 2),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'store_count' => count($branchIds),
            ],
        ]);
    }

    public function adoptionRate(Request $request)
    {
        $validated = $request->validate([
            'branch' => ['nullable'],
            'branch.*' => ['nullable'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        // The service resolves & enforces store access itself; pass the raw branch
        // selection through as store_ids (it filters out 'all' and intersects with
        // the user's accessible stores).
        $data = $this->adoptionRateService->getWeeklyAdoptionTrend([
            'store_ids' => $validated['branch'] ?? [],
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'tab' => AdoptionRateTrackingService::TAB_OVERALL_ADOPTION_RATE,
        ], $request->user());

        return response()->json($data);
    }

    private function resolveDashboardBranchIds($branch, array $branchesOptions): array
    {
        if (is_null($branch) || (is_string($branch) && $branch === 'all') || (is_array($branch) && (empty($branch) || in_array('all', $branch)))) {
            return collect($branchesOptions)->pluck('value')->filter(fn($v) => $v !== 'all')->values()->toArray();
        }

        $branchIds = is_array($branch) ? (array)$branch : [$branch];

        return array_values(array_map('intval', array_filter($branchIds, fn($v) => is_numeric($v))));
    }

    private function deriveSalesMixCategory(string $posCode): string
    {
        $posCode = strtoupper($posCode);

        return match (true) {
            str_starts_with($posCode, 'NON01') => 'Kitchen',
            str_starts_with($posCode, 'NON02') => 'Bakery',
            str_starts_with($posCode, 'NON03') => 'Beverages & Others',
            default => 'Uncategorized',
        };
    }

    private function sortSalesMixMetricRows($rows, string $metric)
    {
        return $rows
            ->sort(function ($a, $b) use ($metric) {
                if ($a[$metric] === $b[$metric]) {
                    return strcasecmp($a['pos_code'], $b['pos_code']);
                }

                return $a[$metric] < $b[$metric] ? 1 : -1;
            })
            ->values();
    }

    private function rankSalesMixMetricRows($rows, string $metric)
    {
        $previousValue = null;
        $previousRank = 0;

        return $rows
            ->values()
            ->map(function ($row, $index) use ($metric, &$previousValue, &$previousRank) {
                $value = round((float)$row[$metric], 2);

                if ($previousValue === null || $value !== $previousValue) {
                    $previousRank = $index + 1;
                }

                $previousValue = $value;
                $row['rank'] = $previousRank;

                return $row;
            });
    }

    /**
     * Helper to calculate total sales for a set of branches with fallback to Data Uploader.
     * Returns absolute Php value as a float.
     */
    private function calculateTotalSalesForBranches($branchIds, $time_period, $year, $isBudget = false)
    {
        $currentMonth = Carbon::today()->month;
        $monthsToSum = $time_period == 0 ? range(1, $currentMonth) : [$time_period];

        $branchIds = array_map('intval', (array)$branchIds);
        $storeBranches = StoreBranch::whereIn('id', $branchIds)->get()->keyBy('id');

        $monthColumns = [
            1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr',
            5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'aug',
            9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dec'
        ];

        $total = 0;

        foreach ($branchIds as $branchId) {
            $branch = $storeBranches->get($branchId);
            if (!$branch) continue;

            $branchSales = 0;

            if (!$isBudget) {
                $query = StoreTransactionItem::join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
                    ->where('store_transactions.store_branch_id', $branchId)
                    ->whereYear('store_transactions.order_date', $year);

                if ($time_period == 0) {
                    $query->whereMonth('store_transactions.order_date', '<=', $currentMonth);
                } else {
                    $query->whereMonth('store_transactions.order_date', $time_period);
                }

                $branchSales = (double)$query->sum('store_transaction_items.net_total');
            }

            if ($branchSales <= 0 || $isBudget) {
                $type = $isBudget ? 'Budget' : 'Sales';
                $record = SalesBudget::where('type', $type)
                    ->where('year', $year)
                    ->where('store_code', $branch->branch_code)
                    ->first();

                if ($record) {
                    $fallbackSum = 0;
                    foreach ($monthsToSum as $m) {
                        $fallbackSum += (double)($record->{$monthColumns[$m]} ?? 0);
                    }
                    $branchSales = $fallbackSum;
                }
            }

            $total += (double)$branchSales;
        }

        return (double)$total;
    }

    /**
     * Batched version: runs 3 grouped queries total instead of 3×N queries.
     */
    public function getSalesBudgetChartData($branchIds, $time_period)
    {
        $currentYear  = Carbon::today()->year;
        $previousYear = $currentYear - 1;
        $currentMonth = Carbon::today()->month;

        $monthColumns = [
            1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr',
            5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'aug',
            9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dec'
        ];
        $monthsToSum = $time_period == 0 ? range(1, $currentMonth) : [$time_period];

        // Load all branches once
        $storeBranches = StoreBranch::whereIn('id', $branchIds)->get()->keyBy('id');
        $branchCodes   = $storeBranches->pluck('branch_code')->toArray();

        // 1 query: actual sales grouped by branch for current year
        $actualQuery = StoreTransactionItem::join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
            ->whereIn('store_transactions.store_branch_id', $branchIds)
            ->whereYear('store_transactions.order_date', $currentYear);
        if ($time_period == 0) {
            $actualQuery->whereMonth('store_transactions.order_date', '<=', $currentMonth);
        } else {
            $actualQuery->whereMonth('store_transactions.order_date', $time_period);
        }
        $actualSalesByBranch = $actualQuery
            ->groupBy('store_transactions.store_branch_id')
            ->selectRaw('store_transactions.store_branch_id, SUM(store_transaction_items.net_total) as total')
            ->pluck('total', 'store_branch_id');

        // 1 query: last-year actual grouped by branch
        $lastYearQuery = StoreTransactionItem::join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
            ->whereIn('store_transactions.store_branch_id', $branchIds)
            ->whereYear('store_transactions.order_date', $previousYear);
        if ($time_period == 0) {
            $lastYearQuery->whereMonth('store_transactions.order_date', '<=', $currentMonth);
        } else {
            $lastYearQuery->whereMonth('store_transactions.order_date', $time_period);
        }
        $lastYearSalesByBranch = $lastYearQuery
            ->groupBy('store_transactions.store_branch_id')
            ->selectRaw('store_transactions.store_branch_id, SUM(store_transaction_items.net_total) as total')
            ->pluck('total', 'store_branch_id');

        // 1 query: all SalesBudget records for budget + sales fallback
        $salesBudgetRecords = SalesBudget::whereIn('store_code', $branchCodes)
            ->whereIn('type', ['Budget', 'Sales'])
            ->whereIn('year', [$currentYear, $previousYear])
            ->get()
            ->groupBy(fn($r) => $r->store_code . '|' . $r->type . '|' . $r->year);

        $labels      = [];
        $actualData  = [];
        $budgetData  = [];
        $lastYearData = [];

        foreach ($branchIds as $branchId) {
            $branch = $storeBranches->get($branchId);
            if (!$branch) continue;

            $code = $branch->branch_code;

            // Actual sales (with fallback to SalesBudget type=Sales)
            $actual = (double)($actualSalesByBranch->get($branchId) ?? 0);
            if ($actual <= 0) {
                $record = $salesBudgetRecords->get($code . '|Sales|' . $currentYear)?->first();
                if ($record) {
                    $actual = array_sum(array_map(fn($m) => (double)($record->{$monthColumns[$m]} ?? 0), $monthsToSum));
                }
            }

            // Budget
            $budget = 0;
            $budgetRecord = $salesBudgetRecords->get($code . '|Budget|' . $currentYear)?->first();
            if ($budgetRecord) {
                $budget = array_sum(array_map(fn($m) => (double)($budgetRecord->{$monthColumns[$m]} ?? 0), $monthsToSum));
            }

            // Last year actual (with fallback)
            $lastYear = (double)($lastYearSalesByBranch->get($branchId) ?? 0);
            if ($lastYear <= 0) {
                $record = $salesBudgetRecords->get($code . '|Sales|' . $previousYear)?->first();
                if ($record) {
                    $lastYear = array_sum(array_map(fn($m) => (double)($record->{$monthColumns[$m]} ?? 0), $monthsToSum));
                }
            }

            // Skip stores with no data across all three datasets
            if ($actual <= 0 && $budget <= 0 && $lastYear <= 0) continue;

            $labels[]       = $code;
            $actualData[]   = round($actual / 1000000, 2);
            $budgetData[]   = round($budget / 1000000, 2);
            $lastYearData[] = round($lastYear / 1000000, 2);
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Actual',    'data' => $actualData,   'backgroundColor' => '#4bc0c0'],
                ['label' => 'Budget',    'data' => $budgetData,   'backgroundColor' => '#9ca3af'],
                ['label' => 'Last Year', 'data' => $lastYearData, 'backgroundColor' => '#f97316'],
            ]
        ];
    }

    /**
     * Get Wastage Chart Data (Quantity as line, Amount as bar)
     */
    public function getWastageChartData($branchIds, $time_period)
    {
        $currentYear = Carbon::today()->year;
        $currentMonth = Carbon::today()->month;

        $storeBranches = StoreBranch::whereIn('id', $branchIds)->get()->keyBy('id');

        $query = Wastage::whereIn('store_branch_id', $branchIds)
            ->whereIn('wastage_status', [WastageStatus::APPROVED_LVL2])
            ->whereYear('created_at', $currentYear);

        if ($time_period == 0) {
            $query->whereMonth('created_at', '<=', $currentMonth);
        } else {
            $query->whereMonth('created_at', $time_period);
        }

        $wastageDataByBranch = $query
            ->groupBy('store_branch_id')
            ->select('store_branch_id')
            ->selectRaw('SUM(COALESCE(approverlvl2_qty, approverlvl1_qty, wastage_qty)) as total_qty')
            ->selectRaw('SUM(COALESCE(approverlvl2_qty, approverlvl1_qty, wastage_qty) * cost) as total_amount')
            ->get()
            ->keyBy('store_branch_id');

        $labels = [];
        $qtyData = [];
        $amountData = [];

        foreach ($wastageDataByBranch as $branchId => $data) {
            $branch = $storeBranches->get($branchId);
            if (!$branch) continue;

            $labels[] = $branch->branch_code;
            $qtyData[] = round((float)$data->total_qty, 2);
            $amountData[] = round((float)$data->total_amount, 2);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Wastage Amount',
                    'type' => 'bar',
                    'data' => $amountData,
                    'backgroundColor' => '#f87171',
                    'yAxisID' => 'y1',
                    'order' => 2
                ],
                [
                    'label' => 'Wastage Quantity',
                    'type' => 'line',
                    'data' => $qtyData,
                    'borderColor' => '#3b82f6',
                    'tension' => 0.4,
                    'yAxisID' => 'y',
                    'order' => 1
                ],
            ]
        ];
    }

    public function getDaysPayableOutstanding($branchIds, $cogsAll, $chart_time_period)
    {
        $accountPayableAll = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_order_items.quantity_received', '>', 0)
            ->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost'));

        return $cogsAll > 0 && $accountPayableAll > 0 ? ($accountPayableAll / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getTop10Products($branchIds, $inventory_type)
    {
        // Use inner join instead of whereHas() to avoid a correlated subquery
        // Note: sap_masterfiles has no cost column, so always order by quantity
        $query = ProductInventoryStock::join(
                'sap_masterfiles',
                'product_inventory_stocks.product_inventory_id',
                '=',
                'sap_masterfiles.id'
            )
            ->whereIn('product_inventory_stocks.store_branch_id', $branchIds)
            ->selectRaw('product_inventory_stocks.*, (quantity - used) as stock_on_hand, sap_masterfiles.ItemDescription')
            ->orderBy('stock_on_hand', 'desc');

        return $query->take(10)->get()->map(fn($item) => [
            'name'       => $item->ItemDescription,
            'total_cost' => 0,
            'quantity'   => $item->stock_on_hand,
        ]);
    }

    public function getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period)
    {
        return $cogsAll > 0 ? ($averageInventory / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getEndingInventory($branchIds)
    {
        return ProductInventoryStockManager::query()
            ->whereIn('store_branch_id', $branchIds)
            ->sum('total_cost');
    }

    public function getBeginningInventory($branchIds)
    {
        // Use a subquery join instead of two sequential queries with a large whereIn
        $subquery = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('quantity', '>', 0)
            ->selectRaw('MIN(id) as id')
            ->groupBy('product_inventory_id');

        return ProductInventoryStockManager::joinSub(
                $subquery,
                'first_ids',
                fn($join) => $join->on('product_inventory_stock_managers.id', '=', 'first_ids.id')
            )
            ->sum('product_inventory_stock_managers.total_cost');
    }

    public function getCogs($branchIds, $time_period)
    {
        $cogsQuery = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('total_cost', '<', 0);

        if ($time_period != 0) {
            $cogsQuery->whereMonth('transaction_date', $time_period);
        } else {
            $cogsQuery->whereYear('transaction_date', Carbon::today()->year);
        }

        return number_format(
            $cogsQuery->sum(DB::raw('ABS(total_cost)')),
            2,
            '.',
            ','
        );
    }

    public function getAccountPayable($branchIds, $time_period)
    {
        $accountPayable = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_order_items.quantity_received', '>', 0);

        if ($time_period != 0) {
            $accountPayable->whereMonth('store_orders.order_date', $time_period);
        } else {
            $accountPayable->whereYear('store_orders.order_date', Carbon::today()->year);
        }

        return number_format(
            $accountPayable->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost')),
            2,
            '.',
            ','
        );
    }

    public function getUpcomingInventories($branchIds, $time_period)
    {
        $upcomingInventories = StoreOrderItem::query()
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_orders.order_status', 'committed');

        if ($time_period != 0) {
            $upcomingInventories->whereMonth('store_orders.order_date', $time_period);
        } else {
            $upcomingInventories->whereYear('store_orders.order_date', Carbon::today()->year);
        }

        return number_format(
            $upcomingInventories->sum(DB::raw('store_order_items.quantity_commited * supplier_items.cost')),
            2,
            '.',
            ','
        );
    }

    public function getInventories($branchIds, $time_period)
    {
        $inventoriesQuery = ProductInventoryStockManager::query()
            ->whereIn('store_branch_id', $branchIds);

        if ($time_period != 0) {
            $inventoriesQuery->whereMonth('transaction_date', '<=', $time_period);
        } else {
            $inventoriesQuery->whereYear('transaction_date', Carbon::today()->year);
        }

        return number_format(
            $inventoriesQuery->sum('total_cost'),
            2,
            '.',
            ','
        );
    }

    public function getHighStockProducts($branchIds)
    {
        $query = ProductInventoryStock::with('sapMasterfile')
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile')
            ->select('product_inventory_stocks.*')
            ->selectRaw('(quantity - used) as stock_on_hand')
            ->orderByDesc('stock_on_hand')
            ->take(4)
            ->get()
            ->map(function ($stock) {
                return [
                    'name'  => $stock->sapMasterfile->ItemDescription,
                    'stock' => $stock->quantity - $stock->used,
                ];
            });
        return $query;
    }

    public function getMostUsedProducts($branchIds)
    {
        return ProductInventoryStock::with('sapMasterfile')
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile')
            ->select('product_inventory_stocks.*')
            ->selectRaw('used as total_used')
            ->orderBy('total_used', 'desc')
            ->take(4)
            ->get()
            ->map(function ($stock) {
                return [
                    'name' => $stock->sapMasterfile->ItemDescription,
                    'used' => $stock->used ?? 0
                ];
            });
    }

    public function getLowOnStockItems($branchIds)
    {
        $usageRecords = DB::table('usage_records as ur')
            ->join('usage_record_items as uri', 'ur.id', '=', 'uri.usage_record_id')
            ->join('menus as m', 'uri.menu_id', '=', 'm.id')
            ->join('menu_ingredients as mi', 'm.id', '=', 'mi.menu_id')
            ->whereIn('ur.store_branch_id', $branchIds)
            ->select(
                'mi.product_inventory_id',
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? 'CAST(SUM(CAST(mi.quantity AS DECIMAL(10,2)) * CAST(uri.quantity AS DECIMAL(10,2))) AS DECIMAL(10,2)) as total_quantity_used'
                        : 'SUM(mi.quantity * uri.quantity) as total_quantity_used'
                ),
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? "STRING_AGG(mi.unit, ',') WITHIN GROUP (ORDER BY mi.unit) as units"
                        : "GROUP_CONCAT(DISTINCT mi.unit) as units"
                )
            )
            ->groupBy('mi.product_inventory_id')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->product_inventory_id => $item->total_quantity_used,
                    $item->product_inventory_id . '_units' => $item->units
                ];
            })
            ->toArray();

        $query = ProductInventoryStock::query()
            ->with(['sapMasterfile'])
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile')
            ->get()
            ->filter(function ($stockItem) {
                return ($stockItem->quantity - $stockItem->used) <= 10;
            })
            ->map(function ($stockItem) use ($usageRecords) {
                $units = isset($usageRecords[$stockItem->product_inventory_id . '_units'])
                    ? '(' . str_replace(',', ', ', $usageRecords[$stockItem->product_inventory_id . '_units']) . ')'
                    : '';

                return [
                    'id'             => $stockItem->sapMasterfile->id,
                    'name'           => $stockItem->sapMasterfile->ItemDescription,
                    'inventory_code' => $stockItem->sapMasterfile->ItemCode,
                    'stock_on_hand'  => $stockItem->quantity - $stockItem->used,
                    'recorded_used'  => $stockItem->used,
                    'estimated_used' => $usageRecords[$stockItem->product_inventory_id] ?? 0,
                    'ingredient_units' => $units,
                    'uom'            => $stockItem->sapMasterfile->BaseUOM,
                ];
            });

        return $query->take(10);
    }

    public function test()
    {
        try {
            $to = "admin@gmail.com";
            $otp = random_int(000000, 999999);
            $response = Mail::to($to)->send(new OneTimePasswordMail($otp));
            dd($response);
        } catch (Exception $e) {
            dd($e->getMessage());
        }
    }
}
