<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    /** @use HasFactory<\Database\Factories\UsageRecordFactory> */
    use HasFactory, BelongsToEntity;

    protected $fillable = [
        'store_branch_id',
        'encoder_id',
        'order_number',
        'transaction_period',
        'transaction_date',
        'cashier_id',
        'order_type',
        'sub_total',
        'total_amount',
        'tax_amount',
        'payment_type',
        'discount_amount',
        'discount_type',
        'service_charge',
        'remarks',
    ];

    public function encoder()
    {
        return $this->belongsTo(User::class, 'encoder_id');
    }

    public function usage_record_items()
    {
        return $this->hasMany(UsageRecordItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }
}
