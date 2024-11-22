<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PhpOffice\PhpSpreadsheet\Calculation\Category;

class ProductInventory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, HasSelections;

    protected $fillable = [
        'inventory_category_id',
        'unit_of_measurement_id',
        'name',
        'inventory_code',
        'brand',
        'conversion',
        'cost',
    ];

    protected $casts = [
        'cost' => 'decimal:2'
    ];

    public function getFormattedCostAttribute()
    {
        return '₱' . number_format($this->cost, 2);
    }

    public function product_categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductCategory::class,
            'product_inventory_categories',
            'product_inventory_id',
            'product_category_id'
        )->withTimestamps();
    }

    public function unit_of_measurement()
    {
        return $this->belongsTo(UnitOfMeasurement::class);
    }

    public function inventory_category()
    {
        return $this->belongsTo(InventoryCategory::class);
    }

    public function store_order_items()
    {
        return $this->hasMany(StoreOrderItem::class);
    }
}
