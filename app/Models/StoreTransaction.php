<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\StoreTransactionFactory> */
    use HasFactory;

    protected $fillable = [
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
}
