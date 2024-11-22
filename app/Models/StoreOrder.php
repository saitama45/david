<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrder extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOrderFactory> */
    use HasFactory;

    protected $attributes = [
        'encoder_id',
        'supplier_id',
        'branch_id',
        'approved_by_user_id',
        'order_number',
        'order_date',
        'order_status',
        'order_request_status',
        'remarks',
        'order_approved_date',
    ];

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
