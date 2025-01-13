<?php

namespace App\Models;

use App\Traits\HasSelections;
use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class InventoryCategory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\InventoryCategoryFactory> */
    use HasFactory, ProductInventoryReference, HasSelections, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function product_inventories()
    {
        return $this->hasMany(ProductInventory::class);
    }
}
