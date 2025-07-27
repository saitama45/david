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
use Carbon\Carbon; // Added Carbon for date manipulation

class StockManagementController extends Controller
{
    use InventoryUsage;
    public function index()
    {
        $search = request('search');
        $costCenters = CostCenter::select(['name', 'id'])->get()->pluck('name', 'id');

        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        Log::info("StockManagementController: Index method called for branchId: {$branchId}");

        $productsQuery = SAPMasterfile::query()
            ->leftJoin('product_inventory_stocks', function ($join) use ($branchId) {
                $join->on('sap_masterfiles.id', '=', 'product_inventory_stocks.product_inventory_id')
                     ->where('product_inventory_stocks.store_branch_id', '=', $branchId);
            })
            ->select(
                // Select MIN(id) to get a single representative ID for the grouped product
                DB::raw('MIN(sap_masterfiles.id) as id'),
                'sap_masterfiles.ItemDescription as name',
                'sap_masterfiles.ItemCode as inventory_code',
                'sap_masterfiles.BaseUOM as uom',
                // Use MAX to handle potential duplicates in product_inventory_stocks if unique constraint is missing
                DB::raw('COALESCE(MAX(product_inventory_stocks.quantity), 0) - COALESCE(MAX(product_inventory_stocks.used), 0) as stock_on_hand'),
                DB::raw('COALESCE(MAX(product_inventory_stocks.used), 0) as recorded_used')
            )
            ->when($search, function ($query) use ($search) {
                $query->where('sap_masterfiles.ItemDescription', 'like', "%{$search}%")
                    ->orWhere('sap_masterfiles.ItemCode', 'like', "%{$search}%");
            })
            // Group by ItemCode and ItemDescription to consolidate logical products
            ->groupBy(
                'sap_masterfiles.ItemCode',
                'sap_masterfiles.ItemDescription',
                'sap_masterfiles.BaseUOM' // Also group by UOM as it's a non-aggregated selected column
            )
            ->orderBy('name');

        Log::info('StockManagementController: Products query SQL: ' . $productsQuery->toSql());
        Log::info('StockManagementController: Products query Bindings: ' . json_encode($productsQuery->getBindings()));


        $products = $productsQuery->paginate(10);

        $products->getCollection()->each(function ($product) {
            Log::info("StockManagementController: Product '{$product->name}' (ID: {$product->id}) - SOH: {$product->stock_on_hand}, Recorded Used: {$product->recorded_used}");
        });

        // Log the final paginated products collection before sending to Inertia
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
        $branchId = $request->only('branchId')['branchId'] ?? $branches->keys()->first();

        // Get the current stock on hand for this product and branch
        $currentProductStock = ProductInventoryStock::where('product_inventory_id', $id)
                                                    ->where('store_branch_id', $branchId)
                                                    ->first();

        // Calculate the current SOH (quantity - used)
        $currentSOH = $currentProductStock ? ($currentProductStock->quantity - $currentProductStock->used) : 0;

        // Fetch all history records for the product and branch, ordered chronologically
        $rawHistory = ProductInventoryStockManager::with(['cost_center', 'sapMasterfile', 'purchaseItemBatch.storeOrderItem.store_order']) // Eager load relationships
            ->where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc') // Ensure consistent ordering for running balance
            ->get();

        $processedHistory = collect(); // Use a Laravel collection for easier manipulation

        // Calculate the SOH *before* any transactions in the rawHistory
        // Sum of all quantities in the fetched history, considering 'log_usage' as negative
        $totalQuantityInRawHistory = $rawHistory->sum(function($item) {
            return ($item->action === 'log_usage') ? -$item->quantity : $item->quantity;
        });

        // The SOH before the first transaction in this history subset
        $initialSOHForDisplay = $currentSOH - $totalQuantityInRawHistory;

        // Add the conceptual "Initial Stock Balance" entry
        $processedHistory->push((object)[
            'id' => 'initial', // Unique ID for this conceptual entry
            'quantity' => 0, // No change for initial state
            'action' => 'initial_balance', // Renamed action for clarity
            'cost_center' => null,
            'unit_cost' => 0,
            'total_cost' => 0,
            // Use the date of the earliest transaction, or today if no transactions
            'transaction_date' => $rawHistory->first() ? Carbon::parse($rawHistory->first()->transaction_date)->format('Y-m-d') : Carbon::today()->format('Y-m-d'),
            'remarks' => 'Initial Stock Balance',
            'running_soh' => $initialSOHForDisplay, // This is the SOH at that point
            'sap_masterfile' => $currentProductStock ? $currentProductStock->sapMasterfile : null, // Attach sapMasterfile for consistency
            'purchase_item_batch' => null, // Ensure this is null for initial balance
        ]);

        $runningSOH = $initialSOHForDisplay; // Start running SOH from this initial balance

        // Process actual history records to calculate running SOH
        foreach ($rawHistory as $item) {
            $quantityChange = $item->quantity;
            if ($item->action === 'log_usage') {
                $quantityChange = -$item->quantity; // Usage decreases stock
            }
            $runningSOH += $quantityChange;
            $item->running_soh = $runningSOH;
            $processedHistory->push($item);
        }

        Log::info('StockManagementController: Processed History for Show:', ['history' => $processedHistory->toArray()]);

        // Paginate the processed history
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
            'id' => ['required'], // This is the SAPMasterfile ID
            'store_branch_id' => ['required'],
            'cost_center_id' => ['required'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'remarks' => ['sometimes']
        ], [
            'cost_center_id.required' => 'Cost Center is required.'
        ]);


        DB::beginTransaction();
        // Find the stock entry using SAPMasterfile ID
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

        $this->handleInventoryUsage($validated);

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
            'id' => ['required'], // This is the SAPMasterfile ID
            'store_branch_id' => ['required'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'remarks' => ['sometimes']
        ]);


        DB::beginTransaction();
        // Find or create the stock entry using SAPMasterfile ID
        $productStock = ProductInventoryStock::firstOrNew([
            'product_inventory_id' => $validated['id'],
            'store_branch_id' => $validated['store_branch_id']
        ]);

        // If it's a new stock entry, initialize quantities
        if (!$productStock->exists) {
            $productStock->quantity = 0;
            $productStock->recently_added = 0;
            $productStock->used = 0;
        }

        $batch = PurchaseItemBatch::create([
            'product_inventory_id' => $validated['id'], // Use SAPMasterfile ID
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
            'product_inventory_id' => $validated['id'], // Use SAPMasterfile ID
            'store_branch_id' => $validated['store_branch_id'],
            'quantity' => $validated['quantity'],
            'action' => 'add_quantity',
            'transaction_date' => $validated['transaction_date'],
            'unit_cost' => $validated['unit_cost'],
            'total_cost' => $validated['unit_cost'] * $validated['quantity'],
            'remarks' => $validated['remarks']
        ]);

        DB::commit();
    }
}
