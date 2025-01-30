<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryHistory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryHistoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'product_inventory_id',
        'inventory_category_id',
        'unit_of_measurement_id',
        'name',
        'barcode',
        'inventory_code',
        'category_a', // new
        'category_b', // new
        'brand',
        'packaging', // new
        'conversion',
        'cost',
        'is_active',
        'effective_date'
    ];

    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }
}
