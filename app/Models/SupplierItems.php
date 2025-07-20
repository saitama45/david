<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Support\Facades\DB; 

class SupplierItems extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'supplier_items';

    protected $fillable = [
        'ItemCode',
        'item_name',
        'SupplierCode',
        'category',
        'brand',
        'classification',
        'packaging_config',
        'config',
        'uom',
        'cost',
        'srp',
        'is_active',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'cost' => 'float', // CRITICAL FIX: Explicitly cast 'cost' to float
    ];

    protected $primaryKey = 'id';
    public $incrementing = true;

   // Define the options scope to return ItemCode as value and a concatenated string as label
    public function scopeOptions(Builder $query)
    {
        // Select ItemCode as the value, and a concatenated string for the label
        return $query->select(
                'ItemCode',
                DB::raw("CONCAT(item_name, ' (', ItemCode, ') ', uom) as name")
            )
            ->pluck('name', 'ItemCode'); // Pluck the 'name' (concatenated string) as value and 'ItemCode' as key
    }

    // relationship back to Supplier
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'SupplierCode', 'supplier_code');
    }

    public function sapMasterfile()
    {
        return $this->hasOne(SAPMasterfile::class, 'ItemCode', 'ItemCode');
    }
}
