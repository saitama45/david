<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovedOrderController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier', 'ordered_item_receive_dates' => function ($query) {
            $query->where('is_approved', true);
        }])->whereHas('ordered_item_receive_dates', function ($query) {
            $query->where('is_approved', true);
        });

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        $orders = $query
            ->latest()
            ->paginate(10);

        return Inertia::render('ApprovedOrder/Index', [
            'orders' => $orders
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();

        return Inertia::render('ApprovedOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }
}
