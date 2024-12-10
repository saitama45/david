<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryStock extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockFactory> */
    use HasFactory;

    protected $fillable = [
        'product_inventory_id',
        'store_branch_id',
        'quantity',
        'recently_added',
        'used',
    ];

    public function product()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }
}
