<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCost extends Model
{
    /** @use HasFactory<\Database\Factories\ProductCostFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'cost',
        'srp1',
        'srp2',
    ];
}
