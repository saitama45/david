<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class MenuIngredient extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\MenuIngredientFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = ['menu_id', 'product_inventory_id', 'quantity', 'unit'];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function product()
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }
}
