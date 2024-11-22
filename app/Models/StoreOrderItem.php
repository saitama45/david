<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'store_order_id',
        'product_inventory_id',
        'quantity_ordered',
        'quantity_received',
        'remarks',
    ];
}

