<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DaysPayableOutStanding extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $chart_time_period = request('chart_time_period') ?? 0;

        $accountPayableAll = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->join('product_inventories', 'store_order_items.product_inventory_id', '=', 'product_inventories.id')
            ->where('store_orders.store_branch_id', $branchId)
            ->where('store_order_items.quantity_received', '>', 0)
            ->sum(DB::raw('store_order_items.quantity_received * product_inventories.cost'));

        $cogsAll = ProductInventoryStockManager::where('store_branch_id', $branchId)
            ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));

        return Inertia::render('DaysPayableOutstanding/Index', [
            'filters' => request()->only(['branchId', 'search', 'chart_time_period']),
            'branches' => $branches,
            'accountPayable' => number_format($accountPayableAll, 2, '.', ','),
            'costOfGoods' => number_format($cogsAll, 2, '.', ',')
        ]);
    }
}
