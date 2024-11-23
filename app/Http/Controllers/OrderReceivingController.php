<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderReceivingController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->where('order_request_status', OrderRequestStatus::APRROVED->value);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        $orders = $query
            ->latest()
            ->paginate(10);

        return Inertia::render('OrderReceiving/Index', [
            'orders' => $orders
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();

        return Inertia::render('OrderReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function receive(Request $request, $id)
    {
        $orderedItem = StoreOrderItem::with('store_order')->findOrFail($id);
        $order = $orderedItem->store_order;
        $totalOrderedQuantity = $order->store_order_items->sum('quantity_ordered');
        $totalQuantityReceived = $order->store_order_items->sum('quantity_received');

        $order->order_status = OrderStatus::PARTIALLY_RECEIVED->value;
        if ($totalOrderedQuantity === $totalQuantityReceived) $order->order_status = OrderStatus::RECEIVED->value;

        $quantityToReceive = $orderedItem->quantity_ordered - $orderedItem->quantity_received;

        $validated = $request->validate([
            'quantity_received' => [
                'required',
                'numeric',
                'min:0',
                "max:{$quantityToReceive}"
            ]
        ], [
            'quantity_received.max' => "You can only receive up to {$quantityToReceive} items for this order."
        ]);

        DB::beginTransaction();
        $orderedItem->quantity_received += $validated['quantity_received'];
        $orderedItem->save();
        $order->save();
        DB::commit();

        return redirect()->back();
    }
}
