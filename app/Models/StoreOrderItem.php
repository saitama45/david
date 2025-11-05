<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Models\SAPMasterfile;

class StoreOrderItem extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreOrderItemFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $touches = ['store_order'];

    // Ensure sapMasterfile relationship is always loaded for JSON serialization
    protected $with = ['sapMasterfile'];

    // Append computed attributes to JSON
    protected $appends = [
        'item_description',
        'item_uom'
    ];

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

    public function sapMasterfile()
    {
        // Link 'item_code' (on this model) to 'ItemCode' (on SAPMasterfile)
        return $this->belongsTo(SAPMasterfile::class, 'item_code', 'ItemCode');
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

    /**
     * Get the item description attribute for JSON serialization
     */
    public function getItemDescriptionAttribute()
    {
        if (!$this->sapMasterfile) {
            return 'Description not available';
        }

        return $this->sapMasterfile->ItemDescription ?:
               $this->sapMasterfile->ItemName ?:
               'Description not available';
    }

    /**
     * Get the item UOM attribute for JSON serialization
     */
    public function getItemUomAttribute()
    {
        // Use the actual UOM field from store_order_items table
        return $this->uom ?: '';
    }
}
