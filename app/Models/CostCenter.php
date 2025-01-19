<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    /** @use HasFactory<\Database\Factories\CostCenterFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function stock_managements()
    {
        return $this->hasMany(ProductInventoryStockManager::class);
    }
}
