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
        'sap_so_number', // Added new column
        'remarks',
    ];

    // Explicitly cast created_at and updated_at to datetime objects
    // Laravel will automatically handle the conversion to/from UTC for storage
    // based on your app.timezone setting.
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
