<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class OrderedProduct extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\OrderedProductFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'remarks',
    ];
}
