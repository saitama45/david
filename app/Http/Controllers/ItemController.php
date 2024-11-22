<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\UnitOfMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ItemController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = ProductInventory::query()->with(['inventory_category', 'unit_of_measurement', 'product_categories']);
        if ($search)
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        $items = $query->paginate(10);

        return Inertia::render('Item/Index', [
            'items' => $items,
            'filters' => request()->only(['search'])
        ])->with('success', true);
    }

    public function create()
    {
        $unitOfMeasurements = UnitOfMeasurement::options();
        $inventoryCategories = InventoryCategory::options();
        $productCategories = ProductCategory::options();
        return Inertia::render('Item/Create', [
            'unitOfMeasurements' => $unitOfMeasurements,
            'inventoryCategories' => $inventoryCategories,
            'productCategories' => $productCategories
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_category_id' => ['required', 'exists:inventory_categories,id'],
            'unit_of_measurement_id' => ['required', 'exists:unit_of_measurements,id'],
            'conversion' => ['required', 'numeric', 'min:1'],
            'name' => ['required'],
            'brand' => ['sometimes'],
            'inventory_code' => ['required', 'unique:product_inventories,inventory_code'],
            'cost' => ['required'],
            'categories' => ['required', 'array'],
            'categories.*' => ['exists:product_categories,id']
        ]);

        DB::transaction(function () use ($validated) {
            $product = ProductInventory::create(Arr::except($validated, ['categories']));
            $product->product_categories()->attach($validated['categories']);
        });

        return redirect()->route('items.index');
    }
}
