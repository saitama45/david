<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use App\Traits\HasSelections;
use App\Traits\traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class InventoryCategory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\InventoryCategoryFactory> */
    use HasFactory, ProductInventoryReference, HasSelections, \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function product_inventories()
    {
        return $this->hasMany(ProductInventory::class);
    }
}
