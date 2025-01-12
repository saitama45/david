<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class DeliveryReceipt extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\DeliveryReceiptFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'store_order_id',
        'delivery_receipt_number',
        'remarks'
    ];

    protected $casts = [
        'created_at' => 'datetime:F d, Y H:i a'
    ];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
