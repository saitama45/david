<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryStock extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

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

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }
}
