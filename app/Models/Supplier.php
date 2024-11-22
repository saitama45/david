<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'supplier_code',
        'name',
        'is_active',
        'remarks',
    ];

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }
}
