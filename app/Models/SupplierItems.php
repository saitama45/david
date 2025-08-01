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
     * both the ItemCode (via the sapMasterfiles relationship) and the SupplierItem's 'uom'
     * (which corresponds to SAPMasterfile's AltUOM).
     *
     * This accessor will first check if the 'sapMasterfiles' relationship has already been loaded
     * (e.g., by using `->with('sapMasterfiles')` in the controller).
     * If loaded, it filters the collection in memory to avoid N+1 queries.
     * If not loaded, it performs a new query, which can lead to N+1.
     */
    public function getSapMasterfileAttribute()
    {
        // Log the SupplierItem's details when the accessor is called
        // Log::debug("Accessing sap_master_file for SupplierItem ID: " . ($this->id ? $this->id : 'N/A') . ", ItemCode: " . ($this->ItemCode ? $this->ItemCode : 'N/A') . ", SupplierItem UOM: '" . ($this->uom ? $this->uom : 'N/A') . "'");

        // Convert the SupplierItem's UOM to uppercase for case-insensitive comparison
        $supplierItemUomUpper = strtoupper($this->uom);

        // Check if the 'sapMasterfiles' relationship has already been loaded
        if ($this->relationLoaded('sapMasterfiles')) {
            // Filter the loaded collection in memory to find the matching AltUOM (converted to uppercase)
            $matchingSapMasterfile = $this->sapMasterfiles->first(function ($sapMasterfile) use ($supplierItemUomUpper) {
                return strtoupper($sapMasterfile->AltUOM) === $supplierItemUomUpper;
            });
            
            // Log whether a match was found from the loaded relationship, and the BaseQty
            // CRITICAL FIX: Use getRawOriginal() for robust string conversion for logging
            // Log::debug("sapMasterfiles relationship loaded. Matching AltUOM '{$this->uom}' found: " . ($matchingSapMasterfile ? 'Yes' : 'No') . " BaseQty: " . ($matchingSapMasterfile ? $matchingSapMasterfile->getRawOriginal('BaseQTY') : 'N/A'));
            
            // NEW: Log the full matching SAPMasterfile object for detailed debugging
            if ($matchingSapMasterfile) {
                // Log::debug("Full matching SAPMasterfile object (loaded relation): " . json_encode($matchingSapMasterfile->toArray(), JSON_PRETTY_PRINT));
            }

            // If no direct match is found, log the available AltUOMs for debugging
            if (!$matchingSapMasterfile) {
                Log::debug("No direct match found for AltUOM '{$this->uom}'. Available AltUOMs for ItemCode '{$this->ItemCode}': " . $this->sapMasterfiles->pluck('AltUOM')->implode(', '));
            }
            return $matchingSapMasterfile;
        }

        // Fallback: If the relationship is not loaded, perform a direct query.
        // This will cause an N+1 query if called for multiple SupplierItems in a loop without eager loading.
        // Use DB::raw to convert AltUOM to uppercase in the query for case-insensitive matching
        $matchingSapMasterfile = $this->sapMasterfiles()->where(DB::raw('UPPER(AltUOM)'), $supplierItemUomUpper)->first();
        
        // Log whether a match was found from the direct query, and the BaseQty
        // CRITICAL FIX: Use getRawOriginal() for robust string conversion for logging
        Log::debug("sapMasterfiles relationship NOT loaded. Direct query for AltUOM '{$this->uom}' found: " . ($matchingSapMasterfile ? 'Yes' : 'No') . " BaseQty: " . ($matchingSapMasterfile ? $matchingSapMasterfile->getRawOriginal('BaseQTY') : 'N/A'));
        
        // NEW: Log the full matching SAPMasterfile object for detailed debugging
        if ($matchingSapMasterfile) {
            Log::debug("Full matching SAPMasterfile object (direct query): " . json_encode($matchingSapMasterfile->toArray(), JSON_PRETTY_PRINT));
        }

        return $matchingSapMasterfile;
    }
}

