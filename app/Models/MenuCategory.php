<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class MenuCategory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\MenuCategoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function menus()
    {
        return $this->hasMany(Menu::class, 'category_id');
    }

    public function scopeOptions(Builder $query)
    {
        return $query->select(['id', 'name'])->get()->pluck('name', 'id');
    }
    
}
