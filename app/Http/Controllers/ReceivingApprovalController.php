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
        $order = StoreOrder::where('order_number', $id)->firstOrFail();
        $items = $order->ordered_item_receive_dates()->with('store_order_item.product_inventory')->get();
        return Inertia::render('ReceivingApproval/Show', [
            'order' => $order,
            'items' => $items
        ]);
    }
}
