<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuIngredient extends Model
{
    /** @use HasFactory<\Database\Factories\MenuIngredientFactory> */
    use HasFactory;

    protected $fillable = ['menu_id', 'product_inventory_id', 'quantity', 'unit'];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function product()
    {
        return $this->belongsTo(ProductInventory::class);
    }
}
