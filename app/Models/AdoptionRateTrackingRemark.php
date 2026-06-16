<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdoptionRateTrackingRemark extends Model
{
    use HasFactory, BelongsToEntity;

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
