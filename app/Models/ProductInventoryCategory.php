<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryCategory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryCategoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
      'product_inventory_id',
      'product_category_id',  
    ];

}
