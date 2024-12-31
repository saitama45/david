<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockManagementController extends Controller
{
    public function index()
    {

        $products = ProductInventory::with(['inventory_stocks' => function ($query) {
            $query->where('store_branch_id', 1);
        }, 'unit_of_measurement'])
            ->paginate(10)
            ->through(function ($item) {
                return [
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'stock_on_hand' => $item->inventory_stocks->first()->quantity - $item->inventory_stocks->first()->used,
                    'recorded_used' => $item->inventory_stocks->first()->used,
                    'uom' => $item->unit_of_measurement->name,
                ];
            });

        return Inertia::render('StockManagement/Index', [
            'products' => $products
        ]);
    }

    public function show()
    {
        return Inertia::render('StockManagement/Show');
    }
}
