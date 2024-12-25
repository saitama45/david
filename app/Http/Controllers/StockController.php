<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = ProductInventory::query()->with('unit_of_measurement');
        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }
        $items = $query->paginate();
        return Inertia::render('Stock/Index', [
            'items' => $items,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $data = ProductInventoryStock::with(['product', 'store_branch'])->where('product_inventory_id', $id)->paginate(10);
        return Inertia::render('Stock/Show', [
            'data' => $data
        ]);
    }
}
