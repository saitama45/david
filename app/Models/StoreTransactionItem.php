<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StoreTransactionItem extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreTransactionItemFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'store_transaction_id',
        'product_id', // This now refers to POSMasterfile.id
        'base_quantity',
        'quantity',
        'price',
        'discount', // Default 0
        'line_total',
        'net_total',
        'remarks'
    ];

    /**
     * Get the POSMasterfile product associated with the transaction item.
     * This replaces the old 'menu' relationship.
     */
    public function posMasterfile()
    {
        return $this->belongsTo(POSMasterfile::class, 'product_id', 'id');
    }

    /**
     * Get the POSMasterfileBOM entries (ingredients) for the associated POS Masterfile.
     * This relies on the posMasterfile relationship to get the POSCode, then matches BOMs.
     * Note: This is a "has many through" type of conceptual relationship,
     * but we will implement it by loading posMasterfile and then its posMasterfileBOMs.
     */
    public function posMasterfileBOMs()
    {
        return $this->hasManyThrough(
            POSMasterfileBOM::class,
            POSMasterfile::class,
            'id', // Foreign key on POSMasterfile table (points to product_id on store_transaction_items)
            'POSCode', // Foreign key on POSMasterfileBOM table
            'product_id', // Local key on StoreTransactionItem table
            'POSCode' // Local key on POSMasterfile table
        );
    }

    public function store_transaction()
    {
        return $this->belongsTo(StoreTransaction::class);
    }
}
