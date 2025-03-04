<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StoreOrderItem extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreOrderItemFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'store_order_id',
        'product_inventory_id',
        'quantity_ordered',
        'quantity_approved',
        'quantity_commited', // New
        'quantity_received',
        'total_cost',
        'remarks',
    ];

    protected $casts = [
        'total_cost' => 'decimal:2'
    ];


    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }

    public function cash_pull_out()
    {
        return $this->belongsTo(CashPullOut::class);
    }

    public function ordered_item_receive_dates()
    {
        return $this->hasMany(OrderedItemReceiveDate::class);
    }
}
