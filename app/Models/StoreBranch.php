<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use OwenIt\Auditing\Contracts\Auditable;

class StoreBranch extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreBranchFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'id',
        'branch_code',
        'name',
        'is_active'
    ];

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function scopeOptions(Builder $query)
    {
        return $query->where('is_active', true)->pluck('name', 'id');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_assigned_store_branches',
            'store_branch_id',
            'user_id'
        );
    }

    public function inventory_stock()
    {
        return $this->belongsTo(ProductInventoryStock::class);
    }
}
