<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class OrderedItemReceiveDate extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\OrderedItemReceiveDateFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'store_order_item_id',
        'received_by_user_id',
        'quantity_received',
        'received_date',
        'expiry_date',
        'remarks',
        'is_approved',
    ];

    protected $casts = [
        'received_date' => 'date:F d, Y h:i a',
        'expiry_date' => 'date:F d, Y'
    ];

    public function store_order_item()
    {
        return $this->belongsTo(StoreOrderItem::class);
    }

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }
}
