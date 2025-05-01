<?php

namespace App\Traits;

use App\Models\PurchaseItemBatch;
use Illuminate\Support\Facades\Log;

trait   InventoryUsage
{
    public function handleInventoryUsage($data)
    {
        $quantityUsed = $data['quantity'];
        $accumulatedQuantity = 0;
        while ($quantityUsed != $accumulatedQuantity) {
            $batch = PurchaseItemBatch::where('remaining_quantity', '>', 0)
                ->where('store_branch_id', $data['store_branch_id'])
                ->where('product_inventory_id', $data['id'])
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
                'product_inventory_id' => $data['id'],
                'store_branch_id' => $data['store_branch_id'],
                'cost_center_id' => $data['cost_center_id'],
                'quantity' => -$quantity,
                'action' => 'log_usage',
                'unit_cost' => $batch->unit_cost,
                'total_cost' => -$totalCost,
                'transaction_date' => $data['transaction_date'],
                'remarks' => $data['remarks']
            ]);
        };
    }
}
