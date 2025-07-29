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
     * The specific entry matching the 'uom' will be retrieved via the sap_masterfile accessor.
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
        // Using ternary operator for null handling in string interpolation to avoid syntax error
        Log::debug("Accessing sap_masterfile for SupplierItem ID: " . ($this->id ? $this->id : 'N/A') . ", ItemCode: " . ($this->ItemCode ? $this->ItemCode : 'N/A') . ", SupplierItem UOM: '" . ($this->uom ? $this->uom : 'N/A') . "'");

        // Check if the 'sapMasterfiles' relationship has already been loaded
        if ($this->relationLoaded('sapMasterfiles')) {
            // Filter the loaded collection in memory to find the matching AltUOM
            $matchingSapMasterfile = $this->sapMasterfiles->firstWhere('AltUOM', $this->uom);
            
            // Log whether a match was found from the loaded relationship
            Log::debug("sapMasterfiles relationship loaded. Matching AltUOM '{$this->uom}' found: " . ($matchingSapMasterfile ? 'Yes' : 'No'));
            
            // If no direct match is found, log the available AltUOMs for debugging
            if (!$matchingSapMasterfile) {
                Log::debug("No direct match found for AltUOM '{$this->uom}'. Available AltUOMs for ItemCode '{$this->ItemCode}': " . $this->sapMasterfiles->pluck('AltUOM')->implode(', '));
            }
            return $matchingSapMasterfile;
        }

        // Fallback: If the relationship is not loaded, perform a direct query.
        // This will cause an N+1 query if called for multiple SupplierItems in a loop without eager loading.
        $matchingSapMasterfile = $this->sapMasterfiles()->where('AltUOM', $this->uom)->first();
        
        // Log whether a match was found from the direct query
        Log::debug("sapMasterfiles relationship NOT loaded. Direct query for AltUOM '{$this->uom}' found: " . ($matchingSapMasterfile ? 'Yes' : 'No'));
        
        return $matchingSapMasterfile;
    }
}
