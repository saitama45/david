<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Support\Facades\DB; 

class SupplierItems extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'supplier_items';

    protected $fillable = [
        'ItemCode',
        'item_name',
        'SupplierCode',
        'category',
        'brand',
        'classification',
        'packaging_config',
        'config',
        'uom',
        'cost',
        'srp',
        'is_active',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $primaryKey = 'id';
    public $incrementing = true;

    // new scope to format data for select options
    public function scopeOptions(Builder $query)
    {
        // 'id' for value, and a combined 'item_name (ItemCode)' for label is a good practice
        return $query->select('id', DB::raw("CONCAT(item_name, ' (', ItemCode, ')') as name"))
                     ->pluck('name', 'id');
    }

    // relationship back to Supplier
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'SupplierCode', 'supplier_code');
    }
}