<?php

namespace App\Traits\Traits;

use App\Models\ProductInventory;

trait ProductInventoryReference
{
    public function product_inventories()
    {
        return $this->hasMany(ProductInventory::class);
    }
}
