<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductSalesController extends Controller
{
    public function index()
    {
        $items = ProductInventory::with('store_order_items')
            ->withSum('store_order_items as total_sold', 'quantity_received')
            ->paginate(10);


        return Inertia::render('ProductSales/Index', [
            'items' => $items
        ]);
    }

    public function show($id)
    {
        $product = ProductInventory::with('store_order_items.store_order.store_branch')->findOrFail($id);

        $branchSales = StoreBranch::select('store_branches.id', 'store_branches.name', 'store_branches.branch_code')
            ->leftJoin('store_orders', 'store_branches.id', '=', 'store_orders.store_branch_id')
            ->leftJoin('store_order_items', 'store_orders.id', '=', 'store_order_items.store_order_id')
            ->where('store_order_items.product_inventory_id', $id)
            ->groupBy('store_branches.id', 'store_branches.name', 'store_branches.branch_code')
            ->selectRaw('COALESCE(SUM(store_order_items.quantity_received), 0) as total_quantity')
            ->paginate(10);



        return Inertia::render('ProductSales/Show', [
            'product' => $product,
            'branchSales' => $branchSales
        ]);
    }
}
