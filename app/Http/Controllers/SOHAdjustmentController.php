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
            ->get();

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
            $product = ProductInventoryStock::where('product_inventory_id', $item->product_inventory_id)
                ->where('store_branch_id', $validated['branchId'])
                ->first();

            if ($item->quantity > 0) {

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
            } else {
                $quantityUsed = abs($item->quantity);
                $accumulatedQuantity = 0;
                while ($quantityUsed != $accumulatedQuantity) {
                    $batch = PurchaseItemBatch::where('remaining_quantity', '>', 0)
                        ->where('store_branch_id', $validated['branchId'])
                        ->where('product_inventory_id', $item->product_inventory_id)
                        ->orderBy('purchase_date', 'asc')
                        ->first();

                    if (!$batch) break;
                    $remainingQuantity = $batch->remaining_quantity;
                    $totalCost = 0;
                    $quantity = 0;

                    if ($remainingQuantity < $quantityUsed) {
                        $accumulatedQuantity += $remainingQuantity;
                        $quantity = $remainingQuantity;
                        $batch->remaining_quantity = 0;
                        $totalCost = $remainingQuantity * $batch->unit_cost;
                        $batch->save();
                    }
                    if ($remainingQuantity > $quantityUsed) {
                        $quantityNeed = $quantityUsed  - $accumulatedQuantity;
                        $accumulatedQuantity += $quantityNeed;
                        $quantity =  $quantityNeed;
                        $totalCost = $quantityNeed * $batch->unit_cost;
                        $batch->remaining_quantity -= $quantityNeed;
                        $batch->save();
                    }

                    if ($remainingQuantity == $quantityUsed) {
                        $accumulatedQuantity += $remainingQuantity;
                        $totalCost = $remainingQuantity * $batch->unit_cost;
                        $quantity = $remainingQuantity;
                        $batch->remaining_quantity = 0;
                        $batch->save();
                    }


                    $batch->product_inventory_stock_managers()->create([
                        'product_inventory_id' => $item->product_inventory_id,
                        'store_branch_id' => $validated['branchId'],
                        'quantity' => -$quantity,
                        'action' => 'soh_adjustment',
                        'unit_cost' => $batch->unit_cost,
                        'total_cost' => -$totalCost,
                        'is_stock_adjustment' => true,
                        'is_stock_adjustment_approved' => true,
                        'transaction_date' => now(),
                    ]);
                }

                $item->update([
                    'is_stock_adjustment_approved' => true,
                    'action' => 'soh_adjustment',
                ]);
            }
        }
        DB::commit();

        return back();
    }
}
