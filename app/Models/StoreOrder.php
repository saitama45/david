<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrder extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'encoder_id',
        'supplier_id',
        'store_branch_id',
        'approved_by_user_id',
        'order_number',
        'order_date',
        'order_status',
        'order_request_status',
        'remarks',
        'order_approved_date',
    ];

    protected $casts = [
        'created_at' => 'date:F d, Y',
        'order_date' => 'date:F d, Y',
        'order_approved_date' => 'date:F d, Y',
    ];

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store_order_items()
    {
        return $this->hasMany(StoreOrderItem::class);
    }
}
