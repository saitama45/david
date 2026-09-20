<?php

namespace App\Services;

use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Support\StockQuantity;
use Illuminate\Support\Facades\DB;

/** The movement ledger is authoritative; the stock cache is not a count baseline. */
class MonthEndStockAdjustment
{
    public function balance(int $productId, int $branchId, ?string $through = null): string
    {
        $query = ProductInventoryStockManager::where('product_inventory_id', $productId)->where('store_branch_id', $branchId);
        if ($through) $query->whereDate('transaction_date', '<=', $through);
        return StockQuantity::normalize($query->selectRaw("COALESCE(SUM(CASE WHEN action IN ('add','add_quantity') THEN quantity WHEN action IN ('out','deduct','log_usage') THEN -quantity ELSE 0 END), 0) AS balance")->value('balance'));
    }

    public function post(int $productId, int $branchId, $count, string $date, string $remarks, ?string $verifiedBaseline = null): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('Month-end posting requires a transaction.');
        StoreBranch::whereKey($branchId)->lockForUpdate()->firstOrFail();
        $baseline = $verifiedBaseline ?? $this->balance($productId, $branchId, $date);
        $delta = StockQuantity::adjustment($count, $baseline);
        // Keep a zero adjustment as evidence of a verified physical-count boundary.
        ProductInventoryStockManager::create([
            'product_inventory_id' => $productId, 'store_branch_id' => $branchId,
            'quantity' => StockQuantity::absolute($delta), 'action' => StockQuantity::isPositive($delta) || StockQuantity::isZero($delta) ? 'add' : 'out',
            'transaction_date' => $date, 'unit_cost' => 0, 'total_cost' => 0,
            'is_stock_adjustment' => true, 'is_stock_adjustment_approved' => true, 'remarks' => $remarks,
        ]);
        $this->refreshCache($productId, $branchId);
    }

    public function refreshCache(int $productId, int $branchId): void
    {
        ProductInventoryStock::updateOrCreate(['product_inventory_id' => $productId, 'store_branch_id' => $branchId],
            ['quantity' => $this->balance($productId, $branchId), 'recently_added' => 0, 'used' => 0]);
    }
}
