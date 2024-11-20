<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'id',
        'product_type_id',
        'unit_of_measurement_id',
        'inventory_code',
        'name',
        'brand',
        'categ_rep',
        'clasi_rep',
        'conversion',
        'packaging',
        'is_active',
        'cost',
    ];
}
