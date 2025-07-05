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
        'ItemNo',
        'ItemDescription',
        'AltQty',
        'BaseQty',
        'AltUOM',
        'BaseUOM',
        'is_active'
    ];
}
