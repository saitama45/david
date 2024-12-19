<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DTSDeliverySchedule extends Model
{
    /** @use HasFactory<\Database\Factories\DTSDeliveryScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'delivery_schedule_id',
        'store_branch_id',
        'variant'
    ];
}
