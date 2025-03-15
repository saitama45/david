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

    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }
}
