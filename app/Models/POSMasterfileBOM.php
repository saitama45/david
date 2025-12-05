<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Support\Facades\Log;

class POSMasterfileBOM extends Model implements Auditable
{
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'pos_masterfiles_bom';

    protected $fillable = [
        'POSCode',
        'POSDescription',
        'Assembly',
        'ItemCode', // This is the ingredient's ItemCode (from SAPMasterfile)
        'ItemDescription', // This is the ingredient's ItemDescription (from SAPMasterfile)
        'RecPercent',
        'RecipeQty',
        'RecipeUOM',
        'BOMQty',
        'BOMUOM', // This is the ingredient's UOM in the BOM
        'UnitCost',
        'TotalCost',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'RecPercent' => 'decimal:4',
        'RecipeQty' => 'decimal:4',
        'BOMQty' => 'decimal:7',
        'UnitCost' => 'decimal:4',
        'TotalCost' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $primaryKey = 'id';
    public $incrementing = true;

    // Relationship to the POSMasterfile for the main POS item (the assembled product).
    // Uses the new POSCode column for the relationship.
    public function posMasterfile()
    {
        return $this->belongsTo(POSMasterfile::class, 'POSCode', 'POSCode');
    }

    /**
     * Relationship to the SAPMasterfile for the assembled item (if ItemCode links to POSMasterfile).
     * This might not be directly used if POSMasterfileBOM.ItemCode always refers to an SAPMasterfile ingredient.
     * This relationship remains 'ItemCode' to 'POSCode' which suggests that the 'ItemCode' in POSMasterfileBOM could also
     * refer to a finished product in POSMasterfile, in which case it needs to match the POSCode of that product.
     * This is a design choice that needs to be consistent with how `ItemCode` is used in `POSMasterfileBOM`.
     * If `ItemCode` in BOM always refers to an `SAPMasterfile` item, this relationship might be misleading or unnecessary.
     * For now, keeping as is, but noting the potential ambiguity.
     */
    public function assembledItem()
    {
        return $this->belongsTo(POSMasterfile::class, 'ItemCode', 'POSCode');
    }

    /**
     * Accessor to get the specific SAPMasterfile entry that matches
     * both the POSMasterfileBOM's ItemCode (as SAPMasterfile.ItemCode)
     * and the POSMasterfileBOM's BOMUOM (as SAPMasterfile.AltUOM or BaseUOM).
     *
     * This is crucial for linking a BOM ingredient to an actual inventory item.
     */
    public function getSapMasterfileIngredientAttribute()
    {
        // First, try to match BOMUOM against AltUOM in SAPMasterfile
        $sapMasterfile = SAPMasterfile::where('ItemCode', $this->ItemCode)
                                      ->whereRaw('UPPER(AltUOM) = ?', [strtoupper($this->BOMUOM)])
                                      ->first();

        // If not found, try to match against BaseUOM in SAPMasterfile
        if (!$sapMasterfile) {
            $sapMasterfile = SAPMasterfile::where('ItemCode', $this->ItemCode)
                                          ->whereRaw('UPPER(BaseUOM) = ?', [strtoupper($this->BOMUOM)])
                                          ->first();
        }

        if (!$sapMasterfile) {
            Log::warning("No matching SAPMasterfile found for BOM ItemCode: {$this->ItemCode}, BOMUOM: {$this->BOMUOM}");
        }

        return $sapMasterfile;
    }

    /**
     * Relationship to the User who created the record.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship to the User who last updated the record.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
