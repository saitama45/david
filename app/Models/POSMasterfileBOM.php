<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class POSMasterfileBOM extends Model implements Auditable
{
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'pos_masterfiles_bom';

    protected $fillable = [
        'POSCode',
        'POSDescription',
        'Assembly',
        'ItemCode',
        'ItemDescription',
        'RecPercent',
        'RecipeQty',
        'RecipeUOM',
        'BOMQty',
        'BOMUOM',
        'UnitCost',
        'TotalCost',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'RecPercent' => 'decimal:4',
        'RecipeQty' => 'decimal:4',
        'BOMQty' => 'decimal:4',
        'UnitCost' => 'decimal:4',
        'TotalCost' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $primaryKey = 'id'; // Assuming 'id' is the primary key
    public $incrementing = true;

    /**
     * Relationship to the POSMasterfile for the main POS item.
     * Assumes POSCode links to ItemCode in POSMasterfile.
     */
    public function posMasterfile()
    {
        return $this->belongsTo(POSMasterfile::class, 'POSCode', 'ItemCode');
    }

    /**
     * Relationship to the POSMasterfile for the assembled item (if ItemCode links to POSMasterfile).
     */
    public function assembledItem()
    {
        return $this->belongsTo(POSMasterfile::class, 'ItemCode', 'ItemCode');
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
