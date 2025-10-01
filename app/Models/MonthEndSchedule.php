<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthEndSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'calculated_date', // Renamed from scheduled_date
        'status',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'calculated_date' => 'date',
    ];

    // Removed branch() relationship

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function countItems()
    {
        return $this->hasMany(MonthEndCountItem::class, 'month_end_schedule_id');
    }
}
