<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasurement;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ItemController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Product::query();
        if ($search)
            $query->whereAny(['InventoryID', 'InventoryName'], 'like', "%$search%");
        $items = $query->paginate(10);

        return Inertia::render('Item/Index', [
            'items' => $items,
            'filters' => request()->only(['search'])
        ]);
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
            'inventory_code' => ['required'],
            'cost' => ['required'],
            'categories' => ['required', 'array'],
            'categories.*' => ['exists:product_categories,id']
        ]);

        dd($validated);
    }
}
