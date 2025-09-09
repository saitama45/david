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
        'item_code',
        'sap_masterfile_id',
        'quantity_ordered',
        'quantity_approved',
        'quantity_commited',
        'quantity_received',
        'cost_per_quantity',
        'total_cost',
        'uom',
        'remarks',
    ];

    protected $casts = [
        'total_cost' => 'decimal:2'
    ];


    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function supplierItem()
    {
        // CRITICAL FIX: Link 'item_code' (on this model) to 'ItemCode' (on SupplierItems)
        return $this->belongsTo(SupplierItems::class, 'item_code', 'ItemCode');
    }

    public function cash_pull_out()
    {
        return $this->belongsTo(CashPullOut::class);
    }

    public function ordered_item_receive_dates()
    {
        return $this->hasMany(OrderedItemReceiveDate::class);
    }

    public function purchase_item_batch()
    {
        return $this->hasMany(PurchaseItemBatch::class);
    }
}
