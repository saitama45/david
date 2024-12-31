<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\UsageRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockManagementController extends Controller
{
    public function index()
    {
        $search = request('search');
        $usageRecords = UsageRecord::with([
            'usage_record_items.menu.menu_ingredients',
        ])
            ->where('store_branch_id', 3)
            ->get()
            ->flatMap(function ($usageRecord) {
                return $usageRecord->usage_record_items->flatMap(function ($item) {
                    return $item->menu->menu_ingredients->map(function ($ingredient) use ($item) {
                        return [
                            'product_id' => $ingredient->product_inventory_id,
                            'quantity_used' => $ingredient->quantity * $item->quantity
                        ];
                    });
                });
            })
            ->groupBy('product_id')
            ->map(function ($items) {
                return $items->sum('quantity_used');
            });

        $query = ProductInventory::query()->with(['inventory_stocks' => function ($query) {
            $query->where('store_branch_id', 3);
        }, 'unit_of_measurement']);

        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }

        $products = $query
            ->paginate(10)
            ->through(function ($item) use ($usageRecords) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'stock_on_hand' => $item->inventory_stocks->first()->quantity - $item->inventory_stocks->first()->used,
                    'recorded_used' => $item->inventory_stocks->first()->used,
                    'estimated_used' => $usageRecords[$item->id] ?? 0,
                    'uom' => $item->unit_of_measurement->name,
                ];
            });

        return Inertia::render('StockManagement/Index', [
            'products' => $products,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {

        return Inertia::render('StockManagement/Show');
    }
}
