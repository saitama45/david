<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryCostHistory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryCostHistoryFactory> */
    use HasFactory;

    protected $fillable = [
        'product_inventory_id',
        'cost',
        'start_date',
        'end_date'
    ];
    
}
