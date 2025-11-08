<?php

namespace App\Http\Controllers;

use App\Exports\StockManagementListExport;
use App\Exports\StockManagementLogUsageExport;
use App\Exports\StockManagementUpdateExport;
use App\Exports\StockMangementSOHExport;
use App\Imports\UpdateStockManagementAddQuantityImport;
use App\Imports\UpdateStockManagementLogUsageImport;
use App\Imports\UpdateStockManagementSOH;
use App\Models\CostCenter;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Models\StoreBranch;
use App\Models\SAPMasterfile;
use App\Models\UsageRecord;
use App\Traits\InventoryUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StockManagementController extends Controller
{
    use InventoryUsage;

    public function index()
    {
        $search = request('search');
        $costCenters = CostCenter::select(['name', 'id'])->get()->pluck('name', 'id');

        $branches = StoreBranch::options();

        // --- Start More Robust Fix for branchId in index method ---
        $requestedBranchId = request('branchId');
        $branchId = null; // Default to null, meaning no specific branch filter initially

        // If the requested branch ID is 'all' or empty, treat it as no specific branch filter
        if ($requestedBranchId === 'all' || empty($requestedBranchId)) {
            $branchId = null;
        } elseif (is_numeric($requestedBranchId)) {
            $branchId = (int) $requestedBranchId; // Cast to integer if it's a valid number
        }
        // If requestedBranchId is a non-numeric string (other than 'all') and not empty,
        // branchId will remain null, leading to no branch-specific filter in the query.
        // --- End More Robust Fix for branchId in index method ---

        Log::info("StockManagementController: Index method called for branchId: {$branchId}");

        // If no branch is selected, return empty result to avoid slow query on 261k+ rows
        if ($branchId === null) {
            Log::info("StockManagementController: No branch selected, returning empty result");
            $products = new \Illuminate\Pagination\LengthAwarePaginator(
                collect([]),
                0,
                10,
                1,
                ['path' => request()->url(), 'query' => request()->query()]
            );

            return Inertia::render('StockManagement/Index', [
                'products' => $products,
                'branches' => $branches,
                'filters' => request()->only(['search', 'branchId']),
                'costCenters' => $costCenters
            ]);
        }

        // Optimized approach: Use a subquery for stock data with branch filter to drastically reduce rows
        $stockSubquery = DB::table('product_inventory_stocks')
            ->select(
                'product_inventory_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(used) as total_used')
            )
            ->where('store_branch_id', '=', $branchId)
            ->groupBy('product_inventory_id');

        $productsQuery = SAPMasterfile::query()
            ->leftJoinSub($stockSubquery, 'stock', function ($join) {
                $join->on('sap_masterfiles.id', '=', 'stock.product_inventory_id');
            })
            ->select(
                'sap_masterfiles.id',
                'sap_masterfiles.ItemDescription as name',
                'sap_masterfiles.ItemCode as inventory_code',
                'sap_masterfiles.BaseUOM as uom',
                'sap_masterfiles.AltUOM as alt_uom',
                'sap_masterfiles.BaseQty as base_qty',
                DB::raw('COALESCE(stock.total_quantity, 0) - COALESCE(stock.total_used, 0) as stock_on_hand'),
                DB::raw('COALESCE(stock.total_used, 0) as recorded_used'),
                DB::raw('(COALESCE(stock.total_quantity, 0) - COALESCE(stock.total_used, 0)) * COALESCE(sap_masterfiles.BaseQty, 1) as total_base_uom_soh')
            )
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('sap_masterfiles.ItemDescription', 'like', "%{$search}%")
                      ->orWhere('sap_masterfiles.ItemCode', 'like', "%{$search}%");
                });
            })
            ->orderBy('sap_masterfiles.ItemDescription');

        Log::info('StockManagementController: Products query SQL: ' . $productsQuery->toSql());
        Log::info('StockManagementController: Products query Bindings: ' . json_encode($productsQuery->getBindings()));

        $products = $productsQuery->paginate(10)->withQueryString();

        $products->getCollection()->each(function ($product) {
            Log::info("StockManagementController: Product '{$product->name}' (ID: {$product->id}) - SOH: {$product->stock_on_hand}, Recorded Used: {$product->recorded_used}, BaseUOM SOH: {$product->total_base_uom_soh}");
        });

        Log::info('StockManagementController: Final products data sent to Inertia:', ['products_data' => $products->toArray()]);

        // Prepare store summary data for dashboard card
        $storeSummary = null;
        if ($search && $branchId) {
            $selectedBranch = $branches->firstWhere('value', $branchId);
            $productsCollection = $products->getCollection();

            // Group by BaseUOM to create dashboard summary
            $baseUomGroups = $productsCollection->groupBy('uom')->map(function ($group, $baseUom) {
                return [
                    'base_uom' => $baseUom,
                    'total_soh' => $group->sum('stock_on_hand'),
                    'total_base_uom_soh' => $group->sum('total_base_uom_soh'),
                    'item_count' => $group->count(),
                    'primary_item' => $group->first() // Get first item as representative
                ];
            });

            // Get the primary BaseUOM (the one with highest total SOH)
            $primaryBaseUom = $baseUomGroups->sortByDesc('total_soh')->first();

            if ($primaryBaseUom) {
                $storeSummary = [
                    'store' => $selectedBranch ? $selectedBranch['label'] : 'Unknown Store',
                    'store_id' => $branchId,
                    'base_uom' => $primaryBaseUom['base_uom'],
                    'total_soh' => $primaryBaseUom['total_soh'],
                    'total_base_uom_soh' => $primaryBaseUom['total_base_uom_soh'],
                    'item_count' => $primaryBaseUom['item_count'],
                    'primary_item' => [
                        'item_code' => $primaryBaseUom['primary_item']->inventory_code,
                        'item_description' => $primaryBaseUom['primary_item']->name,
                        'formatted_name' => $primaryBaseUom['primary_item']->inventory_code . ' - ' . $primaryBaseUom['primary_item']->name
                    ],
                    'all_base_uoms' => $baseUomGroups->values()->toArray(),
                    'dashboard_stats' => [
                        'total_items' => $products->total(),
                        'total_unique_base_uoms' => $baseUomGroups->count(),
                        'overall_total_soh' => $productsCollection->sum('stock_on_hand'),
                        'overall_total_base_uom_soh' => $productsCollection->sum('total_base_uom_soh')
                    ]
                ];
            }
        }

        return Inertia::render('StockManagement/Index', [
            'products' => $products,
            'branches' => $branches,
            'filters' => request()->only(['search', 'branchId']),
            'costCenters' => $costCenters,
            'storeSummary' => $storeSummary
        ]);
    }

    public function export()
    {
        $search = request('search');
        $branchId = request('branchId');
        return Excel::download(
            new StockManagementListExport($search, $branchId),
            'stock-management-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function exportAdd()
    {
        return Excel::download(
            new StockManagementUpdateExport(),
            'stock-management-add-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function exportLog()
    {
        return Excel::download(
            new StockManagementLogUsageExport(),
            'stock-management-log-usage-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function exportSOH()
    {
        return Excel::download(
            new StockMangementSOHExport(),
            'stock-management-soh-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function show(Request $request, $id)
    {
        $branches = StoreBranch::options();

        // --- Start More Robust Fix for branchId in show method ---
        $requestedBranchId = $request->query('branchId');
        $branchId = null; // Default to null

        if ($requestedBranchId === 'all' || empty($requestedBranchId)) {
            // If 'all' or empty, try to find the first *actual numeric* branch ID for a 'show' page
            $firstActualBranch = $branches->filter(function($option) {
                return is_numeric($option['value']);
            })->first();

            $branchId = optional($firstActualBranch)['value'] ?? null;
        } elseif (is_numeric($requestedBranchId)) {
            $branchId = (int) $requestedBranchId;
        }

        // If after all attempts, branchId is still null, it's an invalid state for 'show'
        if ($branchId === null) {
            Log::error("StockManagementController: show method called without a valid branchId. Requested: {$requestedBranchId}");
            return Inertia::render('StockManagement/Show', [
                'branches' => $branches,
                'history' => (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10))->withQueryString(),
                'error' => 'No store branch selected or available for detailed view. Please select a valid branch.',
            ]);
        }
        // --- End More Robust Fix for branchId in show method ---

        Log::info("StockManagementController: show method parameters - Product ID: {$id}, Branch ID: {$branchId}");

        $currentProductStock = ProductInventoryStock::where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)
            ->with('sapMasterfile') // Eager load sapMasterfile for name
            ->first();

        Log::info('StockManagementController: Current Product Stock found:', ['exists' => (bool)$currentProductStock, 'data' => $currentProductStock ? $currentProductStock->toArray() : null]);

        // Fetch all history records, ordered chronologically (ASC)
        // CRITICAL FIX: The eager loading chain itself is fine, but the *access* pattern to nested relations
        // needs to use the nullsafe operator to prevent errors when intermediate relations are null.
        $rawHistory = ProductInventoryStockManager::with(['cost_center', 'sapMasterfile', 'purchaseItemBatch.storeOrderItem.store_order'])
            ->where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)
            ->orderBy('transaction_date', 'asc') // Keep chronological order for initial processing
            ->orderBy('id', 'asc') // Then by ID ascending for consistent chronological order
            ->get();

        Log::info('StockManagementController: Raw History Records Fetched Count: ' . $rawHistory->count());
        Log::info('StockManagementController: Raw History Fetched Data (all):', ['data' => $rawHistory->toArray()]);

        $chronologicalTransactions = collect(); // To hold transactions in chronological order with running SOH

        // Separate 'add' and 'out' actions
        $addMovements = $rawHistory->filter(fn($item) => in_array($item->action, ['add', 'add_quantity']))->sortBy('id')->values();
        $outMovements = $rawHistory->filter(fn($item) => in_array($item->action, ['out', 'deduct', 'log_usage']))->sortBy('id')->values();

        $runningSOH = 0; // Initialize running SOH to 0 for the conceptual 'initial_balance'
        Log::info('StockManagementController: Running SOH initialized to: ' . $runningSOH);

        // Add the conceptual "Initial Stock Balance" entry first
        $initialBalanceEntry = (object)[
            'id' => 'initial',
            'purchase_item_batch_id' => null,
            'quantity' => 0,
            'action' => 'BEG BAL',
            'cost_center_id' => null,
            'unit_cost' => 0,
            'total_cost' => 0,
            'transaction_date' => $rawHistory->first() ? Carbon::parse($rawHistory->first()->transaction_date)->subDay()->format('Y-m-d') : Carbon::today()->format('Y-m-d'),
            'remarks' => 'Beginning Balance',
            'sap_masterfile' => $currentProductStock ? $currentProductStock->sapMasterfile : null,
            'purchase_item_batch' => null,
            'running_soh' => $runningSOH, // Initial SOH is 0
        ];
        $chronologicalTransactions->push($initialBalanceEntry);
        Log::debug('StockManagementController: Initial Balance Entry added with Running SOH: ' . $initialBalanceEntry->running_soh);

        // Process 'add' movements first to build up stock logically
        foreach ($addMovements as $item) {
            $quantityChange = $item->quantity;
            $runningSOH += $quantityChange;
            $item->running_soh = $runningSOH;

            // CRITICAL FIX: Use nullsafe operator for nested relations to prevent errors on null.
            // If purchaseItemBatch, storeOrderItem, or store_order is null, the chain will safely return null.
            $item->display_ref_no = $item->purchaseItemBatch?->storeOrderItem?->store_order?->order_number ?? 'N/a';
            $item->is_link_ref = (bool)($item->purchaseItemBatch?->storeOrderItem?->store_order?->order_number);
            $item->ref_type = $item->is_link_ref ? 'store-order' : null; // Add ref_type

            $chronologicalTransactions->push($item);
            Log::debug("StockManagementController: After ADD Item ID {$item->id}, Action: {$item->action}, Quantity: {$item->quantity}, Running SOH: {$runningSOH}, Display Ref: {$item->display_ref_no}");
        }

        // Then process 'out' movements to deduct from stock logically
        foreach ($outMovements as $item) {
            $quantityChange = -$item->quantity; // Out actions are negative
            $runningSOH += $quantityChange;
            $item->running_soh = $runningSOH;

            // For 'out' actions, check for Interco transfer first
            if (preg_match('/Interco transfer to .* \(Interco: (.*?)\)/', $item->remarks, $matches)) {
                $item->display_ref_no = $matches[1]; // Extracted interco_number
                $item->is_link_ref = true;
                $item->ref_type = 'interco'; // Set ref_type to interco
            } elseif (preg_match('/Receipt No\. (\d+)/', $item->remarks, $matches)) {
                $item->display_ref_no = $matches[1];
                $item->is_link_ref = false;
                $item->ref_type = 'receipt'; // Or some other type if needed
            } else {
                $item->display_ref_no = 'N/a';
                $item->is_link_ref = false;
                $item->ref_type = null;
            }
            $chronologicalTransactions->push($item);
            Log::debug("StockManagementController: After OUT Item ID {$item->id}, Action: {$item->action}, Quantity: {$item->quantity}, Running SOH: {$runningSOH}, Display Ref: {$item->display_ref_no}");
        }

        // Now, prepare the final display order: newest transactions first, then initial balance last.
        // Sort the entire chronologicalTransactions collection by ID descending for display.
        $processedHistory = $chronologicalTransactions->sortByDesc(function ($item) {
            return is_numeric($item->id) ? $item->id : -1; // Treat 'initial' as having a very low ID for sorting to the bottom
        })->values();

        Log::info('StockManagementController: Processed History for Show (final order):', ['history' => $processedHistory->toArray()]);

        $perPage = 10;
        $currentPage = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $pagedData = $processedHistory->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $historyPaginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $pagedData,
            $processedHistory->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url()]
        );

        return Inertia::render('StockManagement/Show', [
            'branches' => $branches,
            'history' => $historyPaginated
        ]);
    }

    public function logUsage(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'store_branch_id' => ['required'],
            'cost_center_id' => ['required'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'remarks' => ['sometimes']
        ], [
            'cost_center_id.required' => 'Cost Center is required.'
        ]);


        DB::beginTransaction();
        $productStock = ProductInventoryStock::where('product_inventory_id', $validated['id'])
            ->where('store_branch_id', $validated['store_branch_id'])
            ->first();

        if (!$productStock) {
            DB::rollBack();
            return back()->withErrors([
                "quantity" => "Stock entry not found for this product at the selected branch."
            ]);
        }

        $stockOnHand = $productStock->quantity - $productStock->used;
        if ($validated['quantity'] > $stockOnHand) {
            DB::rollBack();
            return back()->withErrors([
                "quantity" => "Quantity used can't be greater than stock on hand. (Stock on hand: " . number_format($stockOnHand, 2) . ")"
            ]);
        }
        $productStock->used += $validated['quantity'];
        $productStock->save();

        ProductInventoryStockManager::create([
            'product_inventory_id' => $validated['id'],
            'store_branch_id' => $validated['store_branch_id'],
            'cost_center_id' => $validated['cost_center_id'],
            'quantity' => $validated['quantity'],
            'action' => 'out',
            'unit_cost' => 0,
            'total_cost' => 0,
            'transaction_date' => $validated['transaction_date'],
            'remarks' => $validated['remarks'] ?? 'Logged usage from stock management'
        ]);

        DB::commit();

        return back();
    }

    public function importAdd(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx'],
            'branch' => ['required'],
        ]);

        $import = new UpdateStockManagementAddQuantityImport($validated['branch']);

        Excel::import($import, $validated['file']);


        return response()->json([
            'imported' => $import->getImportedData(),
            'errors' => $import->getErrors()
        ]);
    }

    public function importLogUsage(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx'],
            'branch' => ['required'],
        ]);
        $import = new UpdateStockManagementLogUsageImport($validated['branch']);
        Excel::import($import, $validated['file']);

        return response()->json([
            'imported' => $import->getImportedData(),
            'errors' => $import->getErrors()
        ]);
    }

    public function importSOHUpdate(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx'],
            'branch' => ['required'],
        ]);

        $import = new UpdateStockManagementSOH($validated['branch']);
        Excel::import($import, $validated['file']);

        return response()->json([
            'imported' => $import->getImportedData(),
            'errors' => $import->getErrors()
        ]);
    }

    public function addQuantity(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'store_branch_id' => ['required'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'remarks' => ['sometimes']
        ], [
            'cost_center_id.required' => 'Cost Center is required.'
        ]);


        DB::beginTransaction();
        $productStock = ProductInventoryStock::firstOrNew([
            'product_inventory_id' => $validated['id'],
            'store_branch_id' => $validated['store_branch_id']
        ]);

        if (!$productStock->exists) {
            $productStock->quantity = 0;
            $productStock->recently_added = 0;
            $productStock->used = 0;
        }

        $batch = PurchaseItemBatch::create([
            'product_inventory_id' => $validated['id'],
            'purchase_date' => $validated['transaction_date'],
            'store_branch_id' => $validated['store_branch_id'],
            'quantity' => $validated['quantity'],
            'unit_cost' => $validated['unit_cost'],
            'remaining_quantity' => $validated['quantity']
        ]);


        $productStock->quantity += $validated['quantity'];
        $productStock->recently_added = $validated['quantity'];
        $productStock->save();

        $batch->product_inventory_stock_managers()->create([
            'product_inventory_id' => $validated['id'],
            'store_branch_id' => $validated['store_branch_id'],
            'quantity' => $validated['quantity'],
            'action' => 'add',
            'transaction_date' => $validated['transaction_date'],
            'unit_cost' => $validated['unit_cost'],
            'total_cost' => $validated['unit_cost'] * $validated['quantity'],
            'remarks' => $validated['remarks']
        ]);

        DB::commit();
    }
}
