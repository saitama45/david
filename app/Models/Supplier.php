<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use OwenIt\Auditing\Contracts\Auditable;

class Supplier extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory, HasSelections, \OwenIt\Auditing\Auditable;

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
        return $query->whereNot('supplier_code', 'DROPS')->where('is_active', true)->pluck('name', 'id');
    }

    public function scopeOptionsDTS(Builder $query)
    {
        return $query->where('supplier_code', 'DROPS')->where('is_active', true)->get()->pluck('name', 'id');
    }
}
