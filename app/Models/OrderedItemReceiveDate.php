<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderedItemReceiveDate extends Model
{
    /** @use HasFactory<\Database\Factories\OrderedItemReceiveDateFactory> */
    use HasFactory;

    protected $fillable = [
        'store_order_item_id',
        'received_by_user_id',
        'quantity_received',
        'received_date',
        'remarks',
    ];

    protected $casts = [
        'received_date' => 'date:F d, Y h:i a'
    ];

    public function store_order_item()
    {
        return $this->belongsTo(StoreOrderItem::class);
    }

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
