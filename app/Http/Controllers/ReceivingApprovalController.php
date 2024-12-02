<?php

namespace App\Http\Controllers;

use App\Enum\OrderStatus;
use App\Models\OrderedItemReceiveDate;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReceivingApprovalController extends Controller
{
    public function index()
    {
        $orders = StoreOrder::with([
            'supplier',
            'store_branch',
            'ordered_item_receive_dates' => function ($query) {
                $query->where('is_approved', false);
            }
        ])->whereHas('ordered_item_receive_dates', function ($query) {
            $query->where('is_approved', false);
        })->paginate(10);
        return Inertia::render('ReceivingApproval/Index', [
            'orders' => $orders
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::where('order_number', $id)->firstOrFail();
        $items = $order->ordered_item_receive_dates()->with('store_order_item.product_inventory')->where('is_approved', false)->get();;
        return Inertia::render('ReceivingApproval/Show', [
            'order' => $order,
            'items' => $items
        ]);
    }

    public function approveReceivedItem(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
        ]);
        // Approve the receive date
        if (is_array($validated['id'])) {
            foreach ($validated['id'] as $id) {
                DB::beginTransaction();
                $data = OrderedItemReceiveDate::with('store_order_item.product_inventory')->find($id);
                $data->update(['is_approved' => true]);
                $item = $data->store_order_item->product_inventory;
                $item->stock += $data->quantity_received;
                $item->recently_added = $data->quantity_received;

                $orderedItems = $data->store_order_item->store_order->store_order_items;
                $storeOrder = $data->store_order_item->store_order;
                $storeOrder->order_status = OrderStatus::RECEIVED->value;
                foreach ($orderedItems as $item) {
                    if ($item->quantity_ordered > $item->quantity_received) {
                        $storeOrder->order_status = OrderStatus::PARTIALLY_RECEIVED->value;
                    }
                }

                $storeOrder->save();
                $item->save();
                $data->save();
                DB::commit();
            }
        } else {
            DB::beginTransaction();
            $data = OrderedItemReceiveDate::with(['store_order_item.store_order.store_order_items', 'store_order_item.product_inventory'])->find($validated['id']);
            $data->update(['is_approved' => true]);
            $item = $data->store_order_item->product_inventory;
            $item->stock += $data->quantity_received;
            $item->recently_added = $data->quantity_received;

            $orderedItems = $data->store_order_item->store_order->store_order_items;
            $storeOrder = $data->store_order_item->store_order;
            $storeOrder->order_status = OrderStatus::RECEIVED->value;
            foreach ($orderedItems as $item) {
                if ($item->quantity_ordered > $item->quantity_received) {
                    $storeOrder->order_status = OrderStatus::PARTIALLY_RECEIVED->value;
                }
            }

            $storeOrder->save();
            $item->save();
            $data->save();
            DB::commit();
        }




        return back();
    }
}
