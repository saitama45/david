<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrdersCutoff extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'orders_cutoff';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ordering_template',
        'cutoff_1_day',
        'cutoff_1_time',
        'days_covered_1',
        'cutoff_2_day',
        'cutoff_2_time',
        'days_covered_2',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cutoff_1_day' => 'integer',
        'cutoff_2_day' => 'integer',
    ];

    /**
     * Get the DTS delivery schedules for the order cutoff.
     */
    public function dtsDeliverySchedules()
    {
        return $this->hasMany(DTSDeliverySchedule::class, 'variant', 'ordering_template');
    }
}
