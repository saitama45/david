<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Contracts\Auditable;

class StoreBranch extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreBranchFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'id',
        'branch_code',
        'location_code',
        'name',
        'brand_name',
        'brand_code',
        'store_status',
        'tin',
        'complete_address',
        'head_chef',
        'director_operations',
        'vp_operations',
        'store_representative',
        'aom',
        'point_of_contact',
        'contact_number',
        'is_active'
    ];

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function usage_records()
    {
        return $this->hasMany(UsageRecord::class);
    }

    /**
     * Scope a query to return options for select dropdowns, including an "All Branches" option.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeOptions(Builder $query)
    {
        $user = Auth::user();
        $baseQuery = $query->where('is_active', true);

        if ($user) {
            $user->load(['roles', 'store_branches']);
            $hasAdmin = $user->roles->contains('name', 'admin');
            $assignedBranches = $user->store_branches->pluck('id')->toArray();

            if (!$hasAdmin) {
                $baseQuery->whereIn('id', $assignedBranches);
            }
        }
        
        $options = $baseQuery->get()->map(function ($item) {
            // CRITICAL FIX: Use branch_code instead of location_code for the label
            return [
                'label' => $item->name . ' (' . $item->branch_code . ')',
                'value' => $item->id,
            ];
        });

        // Prepend an "All Branches" option with value 'all'
        return $options->prepend([
            'label' => 'All Branches',
            'value' => 'all',
        ]);
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

    public function getDisplayNameAttribute()
    {
        return "[$this->branch_code] - [$this->name]";
    }


    public function inventory_stock()
    {
        return $this->belongsTo(ProductInventoryStock::class);
    }

    public function inventory_stock_used()
    {
        return $this->belongsTo(ProductInventoryStockManager::class);
    }


    public function delivery_schedules()
    {
        return $this->belongsToMany(
            DeliverySchedule::class,
            'd_t_s_delivery_schedules',
            'store_branch_id',
            'delivery_schedule_id'
        )
            ->withPivot('variant');
    }

    public function store_transactions()
    {
        return $this->hasMany(StoreTransaction::class);
    }

    public function cash_pull_outs()
    {
        return $this->hasMany(CashPullOut::class);
    }
}
