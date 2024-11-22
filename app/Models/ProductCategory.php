<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductCategoryFactory> */
    use HasFactory, HasSelections;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function productInventories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductInventory::class,
            'product_inventory_categories',
            'product_inventory_id',
            'product_category_id',
        )->withTimestamps();
    }
}
