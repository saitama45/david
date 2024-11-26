<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductOrderSummaryController extends Controller
{
    public function index()
    {
        $items = ProductInventory::with('store_order_items', 'unit_of_measurement')
            ->withSum('store_order_items', 'quantity_ordered')
            ->withSum('store_order_items', 'quantity_received')
            ->whereHas('store_order_items')
            ->paginate(10);

        return Inertia::render('ProductOrderSummary/Index', [
            'items' => $items
        ]);
    }

    public function show($id)
    {
        $item = ProductInventory::with('store_order_items.store_order', 'store_order_items.store_order.store_branch', 'store_order_items.store_order.supplier', 'unit_of_measurement')->find($id);
        $orders = $item->store_order_items;

        return Inertia::render('ProductOrderSummary/Show', [
            'item' => $item,
            'orders' => $orders
        ]);
    }
}
