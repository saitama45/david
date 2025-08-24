<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryStockManager extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockManagerFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'purchase_item_batch_id',
        'product_inventory_id', // This will store SAPMasterfile IDs
        'store_branch_id',
        'cost_center_id',
        'quantity',
        'action', // 'add' or 'out'
        'unit_cost',
        'total_cost',
        'transaction_date',
        'is_stock_adjustment',
        'is_stock_adjustment_approved',
        'remarks',
    ];

    protected $casts = [
        'quantity' => 'decimal:10', // CRITICAL FIX: Increased decimal precision
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'transaction_date' => 'date',
        'is_stock_adjustment' => 'boolean',
        'is_stock_adjustment_approved' => 'boolean',
    ];

    /**
     * Get the SAP Masterfile product associated with the stock manager entry.
     */
    public function sapMasterfile()
    {
        return $this->belongsTo(SAPMasterfile::class, 'product_inventory_id');
    }

    /**
     * Get the cost center associated with the stock manager entry.
     */
    public function cost_center()
    {
        return $this->belongsTo(CostCenter::class);
    }

    /**
     * Get the purchase item batch associated with the stock manager entry.
     */
    public function purchaseItemBatch()
    {
        return $this->belongsTo(PurchaseItemBatch::class);
    }
}
