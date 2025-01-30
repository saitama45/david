<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class CostCenter extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\CostCenterFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'name',
        'remarks'
    ];

    public function stock_managements()
    {
        return $this->hasMany(ProductInventoryStockManager::class);
    }
}
