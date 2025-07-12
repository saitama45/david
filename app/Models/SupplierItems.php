<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

class SupplierItems extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'supplier_items';

    protected $fillable = [
        'ItemCode', // Changed from ItemNo
        'item_name',
        'SupplierCode',
        'category',       // New
        'brand',          // New
        'classification', // New
        'packaging_config', // New
        'config',         // New
        'uom',            // New
        'cost',           // New
        'srp',            // New
        'is_active',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Ensure 'ItemNo' is used as the unique key for upsert
    protected $primaryKey = 'id';
    public $incrementing = true; // Assuming ItemNo is not auto-incrementing
}
