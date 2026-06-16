<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;

class ProductCategory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductCategoryFactory> */
    use HasFactory, HasSelections, \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function product_inventories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductInventory::class,
            'product_inventory_categories',
            'product_inventory_id',
            'product_category_id',
        )->withTimestamps();
    }
}
