<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

class SAPMasterfile extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'sap_masterfiles';

    protected $fillable = [
        'ItemCode',
        'ItemDescription',
        'AltQty',
        'BaseQty',
        'AltUOM',
        'BaseUOM',
        'is_active'
    ];

    protected $casts = [
        'AltQty' => 'decimal:4', // Cast to decimal with 4 decimal places
        'BaseQty' => 'decimal:4', // Cast to decimal with 4 decimal places
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Ensure 'id' is used as the unique key for upsert
    protected $primaryKey = 'id';
    public $incrementing = true; // Assuming id is not auto-incrementing
}
