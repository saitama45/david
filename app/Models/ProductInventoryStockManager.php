<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryStockManager extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockManagerFactory> */
    use HasFactory;

    protected $fillable = [
        'product_inventory_id',
        'store_branch_id',
        'cost_center_id',
        'quantity',
        'action',
        'remarks'
    ];

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
