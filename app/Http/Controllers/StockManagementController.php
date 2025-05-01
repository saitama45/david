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
use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Models\StoreBranch;
use App\Models\UsageRecord;
use App\Traits\InventoryUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class StockManagementController extends Controller
{
    use InventoryUsage;
    public function index()
    {
        $search = request('search');
        $costCenters = CostCenter::select(['name', 'id'])->get()->pluck('name', 'id');

        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        // First get all products with their UOM
        $products = ProductInventory::with('unit_of_measurement')
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('inventory_code', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate(10);

        // Then get stock data for these products in the selected branch
        $stockData = ProductInventoryStockManager::where('store_branch_id', $branchId)
            ->whereIn('product_inventory_id', $products->pluck('id'))
            ->select([
                'product_inventory_id',
                DB::raw('SUM(CASE WHEN is_stock_adjustment_approved = true THEN quantity ELSE 0 END) as stock_on_hand'),
                DB::raw('SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END) as recorded_used')
            ])
            ->groupBy('product_inventory_id')
            ->get()
            ->keyBy('product_inventory_id');

        // Transform the products with stock data
        $products->getCollection()->transform(function ($product) use ($stockData) {
            $stock = $stockData->get($product->id);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'uom' => $product->unit_of_measurement->name,
                'inventory_code' => $product->inventory_code,
                'stock_on_hand' => $stock ? $stock->stock_on_hand : 0,
                'recorded_used' => $stock ? $stock->recorded_used : 0,
            ];
        });

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

        $history = ProductInventoryStockManager::with('cost_center')
            ->where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)
            ->paginate(10)
            ->withQueryString();


        return Inertia::render('StockManagement/Show', [
            'branches' => $branches,
            'history' => $history
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
        $product = ProductInventoryStock::where('product_inventory_id', $validated['id'])->where('store_branch_id', $validated['store_branch_id'])->first();

        $stockOnHand = $product->quantity - $product->used;
        if ($validated['quantity'] > $stockOnHand) {
            return back()->withErrors([
                "quantity" => "Quantity used can't be greater than stock on hand. (Stock on hand: $stockOnHand)"
            ]);
        }
        $product->used += $validated['quantity'];
        $product->save();

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
            'id' => ['required'],
            'store_branch_id' => ['required'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:1'],
            'transaction_date' => ['required', 'date'],
            'remarks' => ['sometimes']
        ]);


        DB::beginTransaction();
        $product = ProductInventoryStock::where('product_inventory_id', $validated['id'])->where('store_branch_id', $validated['store_branch_id'])->first();

        $batch = PurchaseItemBatch::create([
            'product_inventory_id' => $validated['id'],
            'purchase_date' => $validated['transaction_date'],
            'store_branch_id' => $validated['store_branch_id'],
            'quantity' => $validated['quantity'],
            'unit_cost' => $validated['unit_cost'],
            'remaining_quantity' => $validated['quantity']
        ]);


        $product->quantity += $validated['quantity'];
        $product->recently_added = $validated['quantity'];
        $product->save();

        $batch->product_inventory_stock_managers()->create([
            'product_inventory_id' => $validated['id'],
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
