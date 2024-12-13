<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryCategory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryCategoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
      'product_inventory_id',
      'product_category_id',  
    ];

}
