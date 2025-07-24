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
        // Fetch categories and map them to an array of objects with 'label' and 'value' properties.
        // The '->all()' method is added to ensure the result is a plain PHP array,
        // which Inertia will then correctly serialize into a JSON array for the Vue component.
        // Assuming 'Category' in POSMasterfile stores the category name, so both label and value are 'name'.
        return $query->select(['id', 'name'])->get()->map(function ($item) {
            return ['label' => $item->name, 'value' => $item->name];
        })->all(); // Convert the collection to a plain array
    }
    
}
