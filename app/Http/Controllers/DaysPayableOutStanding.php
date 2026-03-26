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
        $branchId = request('branchId');
        
        $branchOptions = $branches->toArray();
        
        if (is_null($branchId) || $branchId === 'all' || (is_array($branchId) && in_array('all', $branchId))) {
            $branchIds = collect($branchOptions)->pluck('value')->filter(fn($v) => $v !== 'all')->toArray();
        } else {
            $branchIds = is_array($branchId) ? $branchId : [$branchId];
        }
        
        $branchIds = array_map('intval', array_filter($branchIds, fn($v) => is_numeric($v)));

        $chart_time_period = request('chart_time_period') ?? 0;

        $accountPayableAll = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_order_items.quantity_received', '>', 0)
            ->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost'));

        $cogsAll = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));

        return Inertia::render('DaysPayableOutstanding/Index', [
            'filters' => request()->only(['branchId', 'search', 'chart_time_period']),
            'branches' => $branchOptions,
            'accountPayable' => number_format($accountPayableAll, 2, '.', ','),
            'costOfGoods' => number_format($cogsAll, 2, '.', ',')
        ]);
    }
}
