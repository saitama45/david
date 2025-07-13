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
        'product_inventory_id', // This column now acts as the foreign key to supplier_items.id
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

    // RENAMED and UPDATED this relationship to point to SupplierItems
    public function supplierItem() // <--- NEW METHOD NAME
    {
        return $this->belongsTo(SupplierItems::class, 'product_inventory_id', 'id');
        // "This 'product_inventory_id' column in StoreOrderItem
        // is actually the foreign key for the 'id' column in the SupplierItems table."
    }

    // comment out or remove the old product_inventory relationship
    // if it's no longer relevant for items in store orders.
    /*
    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }
    */

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