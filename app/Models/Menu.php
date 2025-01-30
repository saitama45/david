<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Menu extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'category_id',
        'product_id',
        'name',
        'price',
        'remarks'
    ];

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function usage_record_items()
    {
        return $this->hasMany(UsageRecordItem::class);
    }

    public function scopeOptions(Builder $query)
    {
        return $query->select(['id', 'name'])->get()->pluck('name', 'id');
    }

    public function product_inventories()
    {
        return $this->belongsToMany(ProductInventory::class, 'menu_ingredients')
            ->withPivot(['quantity', 'unit'])
            ->withTimestamps();
    }

    public function menu_ingredients()
    {
        return $this->hasMany(MenuIngredient::class);
    }


    public function products()
    {
        return $this->belongsToMany(ProductInventory::class, 'menu_ingredients')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function transactionItems()
    {
        return $this->hasMany(StoreTransactionItem::class, 'product_id', 'product_id');
    }
}
