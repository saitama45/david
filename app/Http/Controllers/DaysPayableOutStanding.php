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

        // The catalog is matched on the FULL key (item + supplier + uom) through a
        // one-row-per-key subquery. Matching on ItemCode alone joined every catalog
        // row that shares the code — across suppliers and units of measure — so a
        // received line was counted once per row (7x for an item like 190A2A), each
        // time against whichever supplier's cost that row happened to carry.
        //
        // Left-joined with a fallback to the line's own cost_per_quantity: keying
        // the join properly means a line whose uom is absent from the catalog no
        // longer matches anything, and an inner join would silently drop it from
        // the payable rather than price it from what was actually ordered.
        $accountPayableAll = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'store_orders.supplier_id')
            ->leftJoinSub(\App\Models\SupplierItems::singleRowPerJoinKey(), 'supplier_items', function ($join) {
                $join->on('supplier_items.ItemCode', '=', 'store_order_items.item_code')
                     ->on('supplier_items.SupplierCode', '=', 'suppliers.supplier_code')
                     ->whereRaw('supplier_items.uom = COALESCE(store_order_items.original_uom, store_order_items.uom)');
            })
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_order_items.quantity_received', '>', 0)
            ->sum(DB::raw('store_order_items.quantity_received * COALESCE(supplier_items.cost, store_order_items.cost_per_quantity, 0)'));

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
