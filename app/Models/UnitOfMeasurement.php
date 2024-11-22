<?php

namespace App\Models;

use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasurement extends Model
{
    /** @use HasFactory<\Database\Factories\UnitOfMeasurementFactory> */
    use HasFactory, ProductInventoryReference;

    protected $fillable = [
        'name',
        'remarks'
    ];
}
