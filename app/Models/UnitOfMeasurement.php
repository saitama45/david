<?php

namespace App\Models;

use App\Traits\HasSelections;
use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasurement extends Model
{
    /** @use HasFactory<\Database\Factories\UnitOfMeasurementFactory> */
    use HasFactory, ProductInventoryReference, HasSelections;

    protected $fillable = [
        'name',
        'remarks'
    ];
}
