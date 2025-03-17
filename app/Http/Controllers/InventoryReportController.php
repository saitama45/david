<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryReportController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? $timePeriods[1];

        $query = ProductInventoryStockManager::query();

        if ($time_period != 0) {
            $query->whereMonth('transaction_date', '<=', $time_period);
        } else {
            $query->whereYear('transaction_date', Carbon::today()->year);
        }

        $query->select(
            'product_inventory_id',
            DB::raw('SUM(quantity) as total_quantity'),
            DB::raw('SUM(total_cost) as total_cost')
        )
            ->with('product')
            ->where('store_branch_id', $branchId)
            ->groupBy('product_inventory_id');

        $inventories = $query->paginate(10);

        $summarizedInventories = $inventories->through(function ($item) {
            return [
                'quantity' => $item->total_quantity,
                'total_cost' => number_format($item->total_cost, 2, '.', ','),
                'item' => $item->product->name,
                'inventory_code' => $item->product->inventory_code
            ];
        });

        return Inertia::render('InventoryReport/Index', [
            'inventories' => $summarizedInventories,
            'branches' => $branches,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'timePeriods' => $timePeriods
        ]);
    }
}
