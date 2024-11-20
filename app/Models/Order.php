<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $attributes = [
        'encoder_id',
        'supplier_id',
        'received_by_id',
        'branch_id',
        'approved_by_id',
        'order_number',
        'order_date',
        'order_status',
        'order_request_status',
        'remarks',
        'order_approved_date',
    ];
}
