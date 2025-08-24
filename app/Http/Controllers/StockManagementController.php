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
        $branchId = request('branchId') ?? (optional($branches->first())['value'] ?? null);

        Log::info("StockManagementController: Index method called for branchId: {$branchId}");

        $productsQuery = SAPMasterfile::query()
            ->leftJoin('product_inventory_stocks', function ($join) use ($branchId) {
                $join->on('sap_masterfiles.id', '=', 'product_inventory_stocks.product_inventory_id')
                    ->where('product_inventory_stocks.store_branch_id', '=', $branchId);
            })
            ->select(
                DB::raw('MIN(sap_masterfiles.id) as id'),
                'sap_masterfiles.ItemDescription as name',
                'sap_masterfiles.ItemCode as inventory_code',
                'sap_masterfiles.BaseUOM as uom',
                'sap_masterfiles.AltUOM as alt_uom',
                DB::raw('COALESCE(MAX(product_inventory_stocks.quantity), 0) - COALESCE(MAX(product_inventory_stocks.used), 0) as stock_on_hand'),
                DB::raw('COALESCE(MAX(product_inventory_stocks.used), 0) as recorded_used')
            )
            ->when($search, function ($query) use ($search) {
                $query->where('sap_masterfiles.ItemDescription', 'like', "%{$search}%")
                    ->orWhere('sap_masterfiles.ItemCode', 'like', "%{$search}%");
            })
            ->groupBy(
                'sap_masterfiles.ItemCode',
                'sap_masterfiles.ItemDescription',
                'sap_masterfiles.BaseUOM',
                'sap_masterfiles.AltUOM'
            )
            ->orderBy('name');

        Log::info('StockManagementController: Products query SQL: ' . $productsQuery->toSql());
        Log::info('StockManagementController: Products query Bindings: ' . json_encode($productsQuery->getBindings()));

        $products = $productsQuery->paginate(10)->withQueryString();

        $products->getCollection()->each(function ($product) {
            Log::info("StockManagementController: Product '{$product->name}' (ID: {$product->id}) - SOH: {$product->stock_on_hand}, Recorded Used: {$product->recorded_used}");
        });

        Log::info('StockManagementController: Final products data sent to Inertia:', ['products_data' => $products->toArray()]);


        return Inertia::render('StockManagement/Index', [
            'products' => $products,
            'branches' => $branches,
            'filters' => request()->only(['search', 'branchId']),
            'costCenters' => $costCenters
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
        $branchId = $request->only('branchId')['branchId'] ?? (optional($branches->first())['value'] ?? null);

        Log::info("StockManagementController: show method parameters - Product ID: {$id}, Branch ID: {$branchId}");

        $currentProductStock = ProductInventoryStock::where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)
            ->with('sapMasterfile')
            ->first();

        Log::info('StockManagementController: Current Product Stock found:', ['exists' => (bool)$currentProductStock, 'data' => $currentProductStock ? $currentProductStock->toArray() : null]);

        // Fetch all history records, ordered chronologically (ASC)
        $rawHistory = ProductInventoryStockManager::with(['cost_center', 'sapMasterfile', 'purchaseItemBatch.storeOrderItem.store_order'])
            ->where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)
            ->orderBy('transaction_date', 'asc') // Keep chronological order for SOH calculation
            ->orderBy('id', 'asc') // Then by ID ascending for consistent chronological order
            ->get();

        Log::info('StockManagementController: Raw History Records Fetched Count: ' . $rawHistory->count());
        Log::info('StockManagementController: Raw History Fetched Data (all):', ['data' => $rawHistory->toArray()]); // Log all raw history

        $chronologicalTransactions = collect(); // To hold transactions in chronological order with running SOH

        // --- CRITICAL FIX START: Re-order transactions for SOH calculation to match desired output ---

        // Separate 'add' and 'out' actions
        $addMovements = $rawHistory->filter(fn($item) => in_array($item->action, ['add', 'add_quantity']));
        $outMovements = $rawHistory->filter(fn($item) => in_array($item->action, ['out', 'deduct', 'log_usage']));

        // Sort them by ID ascending within their groups (this is important for consistent SOH calculation if dates are identical)
        $addMovements = $addMovements->sortBy('id')->values();
        $outMovements = $outMovements->sortBy('id')->values();

        // Start running SOH from 0 for the conceptual 'initial_balance'
        $runningSOH = 0;
        Log::info('StockManagementController: Running SOH initialized to: ' . $runningSOH);

        // Add the conceptual "Initial Stock Balance" entry at the very beginning of the chronological list
        $initialBalanceEntry = (object)[
            'id' => 'initial',
            'purchase_item_batch_id' => null,
            'quantity' => 0,
            'action' => 'initial_balance',
            'cost_center_id' => null,
            'unit_cost' => 0,
            'total_cost' => 0,
            'transaction_date' => $rawHistory->first() ? Carbon::parse($rawHistory->first()->transaction_date)->subDay()->format('Y-m-d') : Carbon::today()->format('Y-m-d'),
            'remarks' => 'Initial Stock Balance',
            'sap_masterfile' => $currentProductStock ? $currentProductStock->sapMasterfile : null,
            'purchase_item_batch' => null,
            'running_soh' => $runningSOH, // Initial SOH is 0
        ];
        $chronologicalTransactions->push($initialBalanceEntry);
        Log::info('StockManagementController: Initial Balance Entry added with Running SOH: ' . $initialBalanceEntry->running_soh);

        // Process 'add' movements first to build up stock
        foreach ($addMovements as $item) {
            $quantityChange = $item->quantity;
            $runningSOH += $quantityChange;
            $item->running_soh = $runningSOH;
            $chronologicalTransactions->push($item);
            Log::debug("StockManagementController: After ADD Item ID {$item->id}, Action: {$item->action}, Quantity: {$item->quantity}, Running SOH: {$runningSOH}");
        }

        // Then process 'out' movements to deduct from stock
        foreach ($outMovements as $item) {
            $quantityChange = -$item->quantity; // Out actions are negative
            $runningSOH += $quantityChange;
            $item->running_soh = $runningSOH;
            $chronologicalTransactions->push($item);
            Log::debug("StockManagementController: After OUT Item ID {$item->id}, Action: {$item->action}, Quantity: {$item->quantity}, Running SOH: {$runningSOH}");
        }

        // Now, prepare the final display order: newest transactions first, then initial balance last.
        // Sort the entire chronologicalTransactions collection by ID descending for display.
        // The 'initial' entry will have ID 'initial', so it will naturally fall to the end when sorting by ID.
        $processedHistory = $chronologicalTransactions->sortByDesc(function ($item) {
            return is_numeric($item->id) ? $item->id : -1; // Treat 'initial' as having a very low ID for sorting to the bottom
        })->values();
        // --- CRITICAL FIX END ---

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
