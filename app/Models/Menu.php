<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'price',
        'remarks'
    ];

    public function scopeOptions(Builder $query)
    {
        return $query->select(['id', 'name'])->get()->pluck('name', 'id');
    }

    public function menuIngredients()
    {
        return $this->hasMany(MenuIngredient::class);
    }


    public function products()
    {
        return $this->belongsToMany(ProductInventory::class, 'menu_ingredients')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
