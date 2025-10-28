<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;
use App\Traits\HasSelections; // Import the trait
use Illuminate\Database\Eloquent\Builder; // Import Builder

class POSMasterfile extends Model implements Auditable
{
    use HasFactory, \OwenIt\Auditing\Auditable, HasSelections; // Add HasSelections trait
    
    protected $table = 'pos_masterfiles';

    protected $fillable = [
        'POSCode',
        'POSDescription',
        'Category',
        'SubCategory',
        'SRP',
        'DeliveryPrice',
        'TableVibePrice',
        'is_active'
    ];

    protected $casts = [
        'SRP' => 'decimal:4', // Cast to decimal with 4 decimal places
        'DeliveryPrice' => 'decimal:4', // Cast to decimal with 4 decimal places
        'TableVibePrice' => 'decimal:4', // Cast to decimal with 4 decimal places
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Ensure 'id' is used as the unique key for upsert
    protected $primaryKey = 'id';
    public $incrementing = true; // Assuming id is not auto-incrementing

    /**
     * Get the POSMasterfileBOM entries (ingredients) for this POS item.
     */
    public function posMasterfileBOMs()
    {
        return $this->hasMany(POSMasterfileBOM::class, 'POSCode', 'POSCode');
    }

    /**
     * Scope a query to return options for select dropdowns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeOptions(Builder $query)
    {
        // Filter by is_active if available
        // Assuming pos_masterfiles table has an 'is_active' column.
        // If not, remove this line or adjust to your schema.
        $baseQuery = $query->where('is_active', true); 

        $options = $baseQuery->get()->map(function ($item) {
            return [
                'label' => $item->POSDescription . ' (' . $item->POSCode . ')', // Using POSDescription and POSCode for label
                'value' => $item->id, // Use 'id' as the value, as it's the foreign key in StoreTransactionItem
            ];
        });

        // No 'All' option needed here as this is for selecting individual menu items for a transaction item.
        return $options;
    }
}
