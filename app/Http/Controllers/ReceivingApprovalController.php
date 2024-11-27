<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReceivingApprovalController extends Controller
{
    public function index()
    {
        $orders = StoreOrder::with(['supplier', 'store_branch'])->whereHas('ordered_item_receive_dates')->paginate(10);
        return Inertia::render('ReceivingApproval/Index', [
            'orders' => $orders
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with([
            'store_branch',
            'supplier',
            'store_order_items',
            'store_order_items.product_inventory',
            'ordered_item_receive_dates',
            'ordered_item_receive_dates.store_order_item',
            'ordered_item_receive_dates.store_order_item.product_inventory'
        ])->where('order_number', $id)->firstOrFail();
        return Inertia::render('ReceivingApproval/Show', [
            'order' => $order
        ]);
    }
}
