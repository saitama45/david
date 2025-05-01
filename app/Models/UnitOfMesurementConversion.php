<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitOfMesurementConversion extends Model
{
    /** @use HasFactory<\Database\Factories\UnitOfMesurementConversionFactory> */
    use HasFactory;

    protected $fillable = [
        'inventory_code',
        'uom_group',
        'alternative_quantity',
        'base_quantity',
        'alternative_uom',
        'base_uom',
    ];

    public function product()
    {
        return $this->belongsTo(ProductInventory::class, 'inventory_code');
    }
}
