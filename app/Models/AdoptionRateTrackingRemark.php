<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdoptionRateTrackingRemark extends Model
{
    use HasFactory;

    protected $fillable = [
        'tab_key',
        'ordering_template',
        'store_branch_id',
        'delivery_date',
        'remarks',
        'updated_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
    ];
}
