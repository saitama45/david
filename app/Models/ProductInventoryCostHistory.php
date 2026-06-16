<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryCostHistory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryCostHistoryFactory> */
    use HasFactory, BelongsToEntity;

    protected $fillable = [
        'product_inventory_id',
        'cost',
        'start_date',
        'end_date'
    ];
    
}
