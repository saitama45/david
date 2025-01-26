<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;
use PhpOffice\PhpSpreadsheet\Calculation\Category;

class ProductInventory extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'inventory_category_id',
        'unit_of_measurement_id',
        'name',
        'barcode',
        'inventory_code',
        'category_a', // new
        'category_b', // new
        'brand',
        'packaging', // new
        'conversion',
        'cost',
        'is_active'
    ];

    protected $casts = [
        'cost' => 'decimal:2'
    ];

    public function getFormattedCostAttribute()
    {
        return '₱' . number_format($this->cost, 2);
    }

    public function history()
    {
        return $this->hasMany(ProductInventoryHistory::class);
    }

    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'menu_ingredients')
            ->withPivot('quantity')
            ->withTimestamps();
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

    public function ordered_item_receive_date()
    {
        return $this->hasManyThrough(OrderedItemReceiveDate::class, StoreOrderItem::class);
    }

    public function getSelectOptionNameAttribute()
    {
        return "$this->name ($this->inventory_code)";
    }

    public function scopeOptions(Builder $query)
    {
        return $query->select(['id', 'name', 'inventory_code'])->get()->pluck('select_option_name', 'id');
    }

    public function inventory_stocks()
    {
        return $this->hasMany(ProductInventoryStock::class);
    }

    public function inventory_stocks_used()
    {
        return $this->hasMany(ProductInventoryStockManager::class);
    }

    protected static function booted()
    {
        static::created(function ($product) {
            $storeBranches = StoreBranch::all();

            DB::beginTransaction();

            foreach ($storeBranches as $branch) {
                ProductInventoryStock::create([
                    'product_inventory_id' => $product->id,
                    'store_branch_id' => $branch->id,
                ]);
            }

            $product->history()->create([
                'inventory_category_id' => $product->getOriginal('inventory_category_id'),
                'unit_of_measurement_id' => $product->getOriginal('unit_of_measurement_id'),
                'name' => $product->getOriginal('name'),
                'barcode' => $product->getOriginal('barcode'),
                'inventory_code' => $product->getOriginal('inventory_code'),
                'category_a' => $product->getOriginal('category_a'),
                'category_b' => $product->getOriginal('category_b'),
                'packaging' => $product->getOriginal('packaging'),
                'brand' => $product->getOriginal('brand'),
                'conversion' => $product->getOriginal('conversion'),
                'cost' => $product->getOriginal('cost'),
                'is_active' => $product->getOriginal('is_active'),
                'effective_date' => now()
            ]);

            DB::commit();
        });


        // static::updated(function ($product) {
        //     $product->history()->create([
        //         'inventory_category_id' => $product->getOriginal('inventory_category_id'),
        //         'unit_of_measurement_id' => $product->getOriginal('unit_of_measurement_id'),
        //         'name' => $product->getOriginal('name'),
        //         'barcode' => $product->getOriginal('barcode'),
        //         'inventory_code' => $product->getOriginal('inventory_code'),
        //         'category_a' => $product->getOriginal('category_a'),
        //         'category_b' => $product->getOriginal('category_b'),
        //         'packaging' => $product->getOriginal('packaging'),
        //         'brand' => $product->getOriginal('brand'),
        //         'conversion' => $product->getOriginal('conversion'),
        //         'cost' => $product->getOriginal('cost'),
        //         'is_active' => $product->getOriginal('is_active'),
        //     ]);
        // });
    }
}
