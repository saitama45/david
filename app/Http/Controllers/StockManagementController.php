<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Models\UsageRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StockManagementController extends Controller
{
    public function index()
    {
        $search = request('search');
        $user = Auth::user();

        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        $usageRecords = DB::table('usage_records as ur')
            ->join('usage_record_items as uri', 'ur.id', '=', 'uri.usage_record_id')
            ->join('menus as m', 'uri.menu_id', '=', 'm.id')
            ->join('menu_ingredients as mi', 'm.id', '=', 'mi.menu_id')
            ->where('ur.store_branch_id', $branchId)
            ->select(
                'mi.product_inventory_id',
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? 'CAST(SUM(CAST(mi.quantity AS DECIMAL(10,2)) * CAST(uri.quantity AS DECIMAL(10,2))) AS DECIMAL(10,2)) as total_quantity_used'
                        : 'SUM(mi.quantity * uri.quantity) as total_quantity_used'
                ),
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? "STRING_AGG(DISTINCT mi.unit, ',') as units"
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



        $query = ProductInventory::query()
            ->with(['unit_of_measurement'])
            ->whereHas('inventory_stocks', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            })
            ->with(['inventory_stocks' => function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            }]);

        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }

        $products = $query
            ->paginate(10)
            ->withQueryString()
            ->through(function ($item) use ($usageRecords) {
                $units = isset($usageRecords[$item->id . '_units'])
                    ? '(' . str_replace(',', ', ', $usageRecords[$item->id . '_units']) . ')'
                    : '';

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'stock_on_hand' => $item->inventory_stocks->first()->quantity - $item->inventory_stocks->first()->used,
                    'recorded_used' => $item->inventory_stocks->first()->used,
                    'estimated_used' => $usageRecords[$item->id] ?? 0,
                    'ingredient_units' => $units,
                    'uom' => $item->unit_of_measurement->name,
                ];
            });


        return Inertia::render('StockManagement/Index', [
            'products' => $products,
            'branches' => $branches,
            'filters' => request()->only(['search', 'branchId'])
        ]);
    }

    public function show(Request $request, $id)
    {
        $branches = StoreBranch::options();
        $branchId = $request->only('branchId')['branchId'] ?? $branches->keys()->first();

        $history = ProductInventoryStockManager::where('product_inventory_id', $id)
            ->where('store_branch_id', $branchId)->paginate(10);

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
            'quantity' => ['required', 'numeric', 'min:1'],
            'remarks' => ['sometimes']
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
        ProductInventoryStockManager::create([
            'product_inventory_id' => $validated['id'],
            'store_branch_id' => $validated['store_branch_id'],
            'quantity' => $validated['quantity'],
            'action' => 'log_usage',
            'remarks' => $validated['remarks']
        ]);

        DB::commit();
    }

    public function addQuantity(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'store_branch_id' => ['required'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'remarks' => ['sometimes']
        ]);


        DB::beginTransaction();
        $product = ProductInventoryStock::where('product_inventory_id', $validated['id'])->where('store_branch_id', $validated['store_branch_id'])->first();


        $product->quantity += $validated['quantity'];
        $product->recently_added = $validated['quantity'];
        $product->save();
        ProductInventoryStockManager::create([
            'product_inventory_id' => $validated['id'],
            'store_branch_id' => $validated['store_branch_id'],
            'quantity' => $validated['quantity'],
            'action' => 'add_quantity',
            'remarks' => $validated['remarks']
        ]);

        DB::commit();
    }
}
