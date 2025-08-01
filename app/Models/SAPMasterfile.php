<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Builder; // Import Builder for scopeOptions

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

    /**
     * Scope a query to return options for select dropdowns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array
     */
    public function scopeOptions(Builder $query)
    {
        // Return an array of objects with 'label' (ItemCode - ItemDescription (AltUOM)) and 'value' (id)
        // This is suitable for PrimeVue Select components.
        return $query->select(['id', 'ItemCode', 'ItemDescription', 'BaseUOM', 'AltUOM']) // Ensure AltUOM is selected
                     ->where('is_active', 1) // Only active products
                     ->get()
                     ->map(function ($item) {
                         return [
                             // Format the label as "ItemCode - ItemDescription (AltUOM)"
                             'label' => $item->ItemCode . ' - ' . $item->ItemDescription . ' (' . $item->AltUOM . ')',
                             'value' => $item->id,
                             'inventory_code' => $item->ItemCode,
                             'unit_of_measurement' => $item->BaseUOM, // Still using BaseUOM for the actual UOM field in the general context
                             'alt_unit_of_measurement' => $item->AltUOM, // Add AltUOM explicitly for the dropdown data
                         ];
                     })->all(); // Convert the collection to a plain array
    }

    public function getBaseQTYAttribute()
    {
        return $this->attributes['BaseQty'];
    }
}
