<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

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
}
