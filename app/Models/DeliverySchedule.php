<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliverySchedule extends Model
{
    /** @use HasFactory<\Database\Factories\DeliveryScheduleFactory> */
    use HasFactory;

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
