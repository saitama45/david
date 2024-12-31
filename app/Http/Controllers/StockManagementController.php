<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\UsageRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StockManagementController extends Controller
{
    public function index()
    {
        $search = request('search');
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        // Usage Records calculation
        $usageRecords = DB::table('usage_records as ur')
            ->join('usage_record_items as uri', 'ur.id', '=', 'uri.usage_record_id')
            ->join('menus as m', 'uri.menu_id', '=', 'm.id')
            ->join('menu_ingredients as mi', 'm.id', '=', 'mi.menu_id')
            ->where('ur.store_branch_id', $branchId)
            ->select(
                'mi.product_inventory_id',
                DB::raw('SUM(mi.quantity * uri.quantity) as total_quantity_used')
            )
            ->groupBy('mi.product_inventory_id')
            ->pluck('total_quantity_used', 'product_inventory_id')
            ->toArray();

        // Product query
        $query = ProductInventory::query()
            ->with(['unit_of_measurement'])
            ->whereHas('inventory_stocks', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            })
            ->with(['inventory_stocks' => function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            }]);

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
            'branches' => $branches,
            'filters' => request()->only(['search', 'branchId'])
        ]);
    }

    public function show($id)
    {

        return Inertia::render('StockManagement/Show');
    }
}
