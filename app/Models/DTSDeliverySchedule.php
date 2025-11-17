<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\StoreBranch;

class DTSDeliverySchedule extends Model
{
    /** @use HasFactory<\Database\Factories\DTSDeliveryScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'delivery_schedule_id',
        'store_branch_id',
        'variant'
    ];

    /**
     * Get the store branch associated with the DTS entry.
     */
    public function store_branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }

    /**
     * Get the delivery schedule associated with the DTS entry.
     */
    public function deliverySchedule(): BelongsTo
    {
        return $this->belongsTo(DeliverySchedule::class, 'delivery_schedule_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'variant', 'supplier_code');
    }
}