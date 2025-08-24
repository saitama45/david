<?php

namespace App\Models;

use App\Traits\HasSelections; // Keep if you're using this trait elsewhere
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
     * Scope a query to return options for select dropdowns.
     * This now returns a Collection of associative arrays with 'label' and 'value'.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeOptions(Builder $query)
    {
        // Fetch the authenticated user and their roles/assigned branches
        $user = Auth::user();
        if ($user) { // Ensure a user is authenticated
            $user->load(['roles', 'store_branches']); // Eager load relationships
            $hasAdmin = $user->roles->contains('name', 'admin');
            $assignedBranches = $user->store_branches->pluck('id')->toArray();

            // Apply branch filtering if the user is not an admin
            if (!$hasAdmin) {
                $query->whereIn('id', $assignedBranches);
            }
        }
        
        // Filter by active branches and then map to 'label' and 'value' for dropdowns
        return $query->where('is_active', true)
                     ->get() // Get the collection of StoreBranch models
                     ->map(function ($branch) {
                         return [
                             'label' => $branch->display_name, // Use the accessor
                             'value' => $branch->id,
                         ];
                     });
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
