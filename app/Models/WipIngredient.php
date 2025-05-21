<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WipIngredient extends Model
{
    /** @use HasFactory<\Database\Factories\WipIngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'wip_id',
        'product_inventory_id',
        'quantity',
        'unit'
    ];

    public function wip()
    {
        return $this->belongsTo(WIP::class);
    }

    public function product()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }
}
