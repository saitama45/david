<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryHistory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'product_inventory_id',
        'inventory_category_id',
        'unit_of_measurement_id',
        'name',
        'barcode',
        'inventory_code',
        'brand',
        'conversion',
        'cost',
        'is_active'
    ];

    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }
}
