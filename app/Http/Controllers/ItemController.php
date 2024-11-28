<?php

namespace App\Http\Controllers;

use App\Imports\ProductInventoryImport;
use App\Models\InventoryCategory;
use App\Models\OrderedItemReceiveDate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\StoreOrder;
use App\Models\UnitOfMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ItemController extends Controller
{
    public function index()
    {
        $search = request('search');
        $filter = request('filter');

        $query = ProductInventory::query()->with(['inventory_category', 'unit_of_measurement', 'product_categories']);

        if ($filter === 'with_cost')
            $query->where('cost', '>', 0);

        if ($filter === 'without_cost')
            $query->where('cost', '===', 0);

        if ($search)
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        $items = $query->paginate(10);

        return Inertia::render('Item/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter'])
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

    public function show($id)
    {
        $item = ProductInventory::with(['inventory_category', 'unit_of_measurement'])->where('inventory_code', $id)->first();

        $orders = OrderedItemReceiveDate::with(['store_order_item', 'store_order_item.store_order', 'store_order_item.store_order.store_branch', 'store_order_item.store_order.supplier'])
            ->whereHas('store_order_item', function ($query) use ($item) {
                $query->where('product_inventory_id', $item->id);
            })
            ->where('is_approved', true)
            ->get();
        return Inertia::render('Item/Show', [
            'item' => $item,
            'orders' => $orders
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

    public function import(Request $request)
    {
        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        Excel::import(new ProductInventoryImport, $request->file('products_file'));
        return redirect()->route('items.index')->with('success', 'Import successful');
    }
}
