<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryCategoryFactory> */
    use HasFactory;

    protected $fillable = [
      'product_inventory_id',
      'product_category_id',  
    ];

}
