<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;
use OwenIt\Auditing\Models\Audit;

class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'phone_number',
        'email',
        'password',
        'remarks',
        'is_active'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class, 'encoder_id');
    }
    public function usage_records()
    {
        return $this->hasMany(UsageRecord::class, 'encoder_id');
    }

    public function store_order_remarks()
    {
        return $this->hasMany(StoreOrderRemark::class);
    }


    public function ordered_item_received_date()
    {
        return $this->hasMany(OrderedItemReceiveDate::class);
    }

    public function store_branches()
    {
        return $this->belongsToMany(
            StoreBranch::class,
            'user_assigned_store_branches',
            'user_id',
            'store_branch_id'
        );
    }

    public function user_roles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function scopeRolesAndAssignedBranches(Builder $query)
    {
        $user = $query->with(['roles', 'store_branches'])->findOrFail(Auth::id());

        $isAdmin = $user->roles->contains('name', 'admin');
        $assignedBranches = $user->store_branches->pluck('id')->toArray();
        return [
            'isAdmin' => $isAdmin,
            'assignedBranches' => $assignedBranches,
            'user' => $user
        ];
    }

    public function getFullNameAttribute()
    {
        return $this->first_name . " " . $this->last_name;
    }

    public function auditEvent($event)
    {
        Audit::create([
            'user_type' => self::class,
            'user_id' => Auth::id(),
            'auditable_type' => self::class,
            'auditable_id'   => $this->id,
            'event'          => $event,
            'url'            => request()->fullUrl(),
            'ip_address'     => request()->ip(),
            'user_agent'     => request()->userAgent(),
            'created_at'     => now(),
        ]);
    }
}
