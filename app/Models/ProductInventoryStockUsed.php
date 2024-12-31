<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryStockUsed extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockUsedFactory> */
    use HasFactory;

    protected $fillable = [
        'product_inventory_id',
        'store_branch_id',
        'quantity',
        'recently_added',
    ];

    public function product()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }
}
