<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The seven weekday rows (MONDAY..SUNDAY), shared by every entity. Deliberately
 * NOT BelongsToEntity: every entity's d_t_s_delivery_schedules pivot points at
 * the same ids (the entity comes from the store), and scoping this lookup hid
 * every non-Nono's store from mass-order templates and store selection.
 */
class DeliverySchedule extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\DeliveryScheduleFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'day',
    ];

    public function store_branches()
    {
        return $this->belongsToMany(StoreBranch::class);
    }

    public function scopeOptions(Builder $query)
    {
        return $query->pluck('day', 'id');
    }

    /**
     * Get all of the DTS delivery schedules for the DeliverySchedule.
     */
    public function dtsDeliverySchedules()
    {
        return $this->hasMany(DTSDeliverySchedule::class);
    }
}
