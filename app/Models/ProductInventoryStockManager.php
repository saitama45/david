<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryStockManager extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockManagerFactory> */
    use HasFactory;

    protected $fillable = [
        'product_inventory_id',
        'store_branch_id',
        'quantity',
        'action',
        'remarks'
    ];

    protected $casts = [
        'created_at' => 'date:F d, Y h:i a',
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
