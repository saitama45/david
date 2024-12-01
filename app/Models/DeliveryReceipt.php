<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryReceipt extends Model
{
    /** @use HasFactory<\Database\Factories\DeliveryReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'store_order_id',
        'delivery_receipt_number',
        'remarks'
    ];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
