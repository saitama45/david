<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory, HasSelections;

    protected $fillable = [
        'supplier_code',
        'name',
        'is_active',
        'remarks',
    ];

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function scopeOptions(Builder $query)
    {
        return $query->where('is_active', true)->pluck('name', 'id');
    }
}
