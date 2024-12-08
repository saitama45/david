<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItemRemark extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemRemarkFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_item_id',
        'action',
        'remarks'
    ];
}
