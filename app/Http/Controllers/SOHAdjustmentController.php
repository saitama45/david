<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SOHAdjustmentController extends Controller
{
    public function index()
    {
        $search = request('search');
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        $items = ProductInventoryStockManager::with('product')
            ->where('store_branch_id', $branchId)
            ->where('is_stock_adjustment_approved', false)
            ->when($search, function ($query) use ($search) {
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('inventory_code', 'like', "%{$search}%");
                });
            })
            ->where('action', 'soh_adjustment')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return Inertia::render('SOHAdjustment/Index', [
            'branches' => $branches,
            'search' => $search,
            'items' => $items,
            'filters' => request()->only(['search', 'branchId']),
        ]);
    }

    public function approveSelectedItems(Request $request)
    {
        $validated = $request->validate([
            'selectedItems' => ['required', 'array'],
            'branchId' => ['required', 'exists:store_branches,id'],
        ]);

        DB::beginTransaction();
        foreach ($validated['selectedItems'] as $itemId) {
            $item = ProductInventoryStockManager::with('product')->find($itemId);

            if ($item->quantity > 0) {

                $product = ProductInventoryStock::where('product_inventory_id', $item->product_inventory_id)
                    ->where('store_branch_id', $validated['branchId'])
                    ->first();

                $batch = PurchaseItemBatch::create([
                    'product_inventory_id' => $item->product_inventory_id,
                    'purchase_date' => now(),
                    'store_branch_id' => $validated['branchId'],
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->product->cost,
                    'remaining_quantity' => $item->quantity,
                ]);

                $product->quantity += $item->quantity;
                $product->recently_added = $item->quantity;
                $product->save();

                $item->update([
                    'purchase_item_batch_id' => $batch->id,
                    'is_stock_adjustment_approved' => true,
                    'action' => 'soh_adjustment',
                    'unit_cost' => $item->product->cost,
                    'total_cost' => $item->product->cost * $item->quantity,
                ]);

                $item->save();
            }
        }
        DB::commit();

        return back();
    }
}
