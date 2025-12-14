<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Facades\Log; // Import Log facade

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
        'area',
        'category2',
        'cost',
        'srp',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'cost' => 'float', // CRITICAL FIX: Explicitly cast 'cost' to float
    ];

    protected $primaryKey = 'id';
    public $incrementing = true;

    // Append the 'sap_master_file' accessor to the model's array/JSON form
    // This ensures that when SupplierItems are serialized (e.g., for Inertia),
    // the result of the getSapMasterfileAttribute() method is automatically included.
    protected $appends = ['sap_master_file'];

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

    /**
     * Define a hasMany relationship to SAPMasterfile based on ItemCode.
     * This relationship will fetch ALL SAPMasterfile entries that share the same ItemCode.
     * The specific entry matching the 'uom' will be retrieved via the sap_master_file accessor.
     */
    public function sapMasterfiles()
    {
        return $this->hasMany(SAPMasterfile::class, 'ItemCode', 'ItemCode');
    }

    /**
     * Custom accessor to get the specific SAPMasterfile entry that matches
     * both the ItemCode and the SupplierItem's 'uom' (AltUOM).
     * Optimized to prevent N+1 queries by preferring loaded relationships.
     */
    public function getSapMasterfileAttribute()
    {
        if (!$this->uom) {
            return null;
        }

        $supplierItemUomUpper = strtoupper($this->uom);

        // Check if the 'sapMasterfiles' relationship has already been loaded
        if ($this->relationLoaded('sapMasterfiles')) {
            return $this->sapMasterfiles->first(function ($sapMasterfile) use ($supplierItemUomUpper) {
                return strtoupper($sapMasterfile->AltUOM) === $supplierItemUomUpper;
            });
        }

        // Fallback: perform a single optimized query
        return SAPMasterfile::where('ItemCode', $this->ItemCode)
            ->where(DB::raw('UPPER(AltUOM)'), $supplierItemUomUpper)
            ->first();
    }
}

