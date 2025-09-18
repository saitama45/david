<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function dtsDeliverySchedules(): HasMany
    {
        return $this->hasMany(DTSDeliverySchedule::class, 'variant', 'supplier_code');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_suppliers',
            'supplier_code',
            'user_id',
            'supplier_code',
            'id'
        );
    }

    /**
     * Original scopeOptions: Returns suppliers with supplier_code as value.
     * This remains unchanged to avoid breaking existing modules.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeOptions(Builder $query)
    {
        return $query->whereNot('supplier_code', 'DROPS')
                     ->where('is_active', true)
                     ->pluck('name', 'supplier_code');
    }

    /**
     * New scopeReportOptions: Returns suppliers with ID as value and formatted label.
     * This is specifically for reports requiring supplier ID in the filter.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeReportOptions(Builder $query)
    {
        return $query->where('is_active', true)
                     ->get()
                     ->map(function ($supplier) {
                         return [
                             'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                             'value' => $supplier->supplier_code,
                         ];
                     });
    }

    public function scopeOptionsDTS(Builder $query)
    {
        return $query->where('supplier_code', 'DROPS')->where('is_active', true)->get()->pluck('name', 'id');
    }
}