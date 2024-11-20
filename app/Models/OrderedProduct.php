<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderedProduct extends Model
{
    /** @use HasFactory<\Database\Factories\OrderedProductFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'remarks',
    ];
}
