<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryStockManager extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockManagerFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'purchase_item_batch_id',
        'product_inventory_id',
        'store_branch_id',
        'cost_center_id',
        'quantity',
        'action',
        'unit_cost',
        'total_cost',
        'transaction_date',
        'is_stock_adjustment',
        'is_stock_adjustment_approved',
        'remarks'
    ];

    // quantity 1 // used 1

    // 5 + 6 = 11

    // 1 + 1 = 2

    // quanitty - used = 9

    public function purchase_item_batch()
    {
        return $this->belongsTo(PurchaseItemBatch::class);
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Asia/Manila')->format('F d, Y h:i a');
    }

    public function cost_center()
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function product()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }
}
