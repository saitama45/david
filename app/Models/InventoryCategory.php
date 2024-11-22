<?php

namespace App\Models;

use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    /** @use HasFactory<\Database\Factories\InventoryCategoryFactory> */
    use HasFactory, ProductInventoryReference;

    protected $fillable = [
        'name',
        'remarks'
    ];
}
