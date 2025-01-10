<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuCategory extends Model
{
    /** @use HasFactory<\Database\Factories\MenuCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function menus()
    {
        return $this->hasMany(Menu::class);
    }

    public function scopeOptions(Builder $query)
    {
        return $query->select(['id', 'name'])->get()->pluck('name', 'id');
    }
    
}
