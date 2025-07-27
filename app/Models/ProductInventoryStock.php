<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ProductInventoryStock extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryStockFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'product_inventory_id', // This column will now store SAPMasterfile IDs
        'store_branch_id',
        'quantity',
        'recently_added',
        'used',
    ];

    /**
     * Get the SAP Masterfile product associated with the stock.
     */
    public function sapMasterfile() // Renamed for clarity, but still uses product_inventory_id column
    {
        return $this->belongsTo(SAPMasterfile::class, 'product_inventory_id');
    }

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }
}
