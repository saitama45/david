<?php

namespace App\Models;

use App\Traits\HasSelections;
use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class UnitOfMeasurement extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\UnitOfMeasurementFactory> */
    use HasFactory, ProductInventoryReference, HasSelections, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'remarks'
    ];

    
}
