<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseItemBatch extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseItemBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'cash_pull_out_item_id',
        'store_order_item_id',
        'store_branch_id',
        'product_inventory_id',
        'purchase_date',
        'quantity',
        'unit_cost',
        'remaining_quantity'
    ];

    public function product_inventory_stock_managers()
    {
        return $this->hasMany(ProductInventoryStockManager::class);
    }

    /**
     * Get the SAP Masterfile product associated with the batch.
     */
    public function sapMasterfile()
    {
        return $this->belongsTo(SAPMasterfile::class, 'product_inventory_id');
    }

    /**
     * Get the store order item that this batch belongs to.
     */
    public function storeOrderItem()
    {
        return $this->belongsTo(StoreOrderItem::class);
    }
}
