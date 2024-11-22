<?php

namespace App\Models;

use App\Traits\HasSelections;
use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    /** @use HasFactory<\Database\Factories\InventoryCategoryFactory> */
    use HasFactory, ProductInventoryReference, HasSelections;

    protected $fillable = [
        'name',
        'remarks'
    ];
}
