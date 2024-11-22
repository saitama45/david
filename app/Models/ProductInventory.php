<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_category_id',
        'unit_of_measurement_id',
        'name',
        'inventory_code',
        'brand',
        'conversion',
        'cost',
    ];
}
