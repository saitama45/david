<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\DeliveryReceipt;
use App\Models\ProductInventoryStock;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderReceivingController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->where('order_request_status', OrderRequestStatus::APRROVED->value);

        $user = Auth::user();

        if ($user->role === 'so_encoder') {
            $query->whereIn('store_branch_id', $user->store_branches->pluck('id'));
        }

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
        $order = StoreOrder::with([
            'delivery_receipts',
            'store_branch',
            'supplier',
            'store_order_items',
            'ordered_item_receive_dates',
            'ordered_item_receive_dates.store_order_item',
            'ordered_item_receive_dates.store_order_item.product_inventory'
        ])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();

        $receiveDatesHistory = $order->ordered_item_receive_dates;

        return Inertia::render('OrderReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory
        ]);
    }

    public function receive(Request $request, $id)
    {
        $orderedItem = StoreOrderItem::with('store_order')->findOrFail($id);
        $order = $orderedItem->store_order;
        $productInventoryStock = ProductInventoryStock::where('product_inventory_id', $orderedItem->product_inventory_id)->where('store_branch_id', $order->store_branch_id)->first();
        // $totalOrderedQuantity = $order->store_order_items->sum('quantity_ordered');
        // $totalQuantityReceived = $order->store_order_items->sum('quantity_received');

        // $quantityToReceive = $orderedItem->quantity_ordered - $orderedItem->quantity_received;

        $validated = $request->validate([
            'quantity_received' => [
                'required',
                'numeric',
                'min:1',
                // "max:{$quantityToReceive}"
            ],
            'received_date' => [
                'required',
                'date_format:Y-m-d\TH:i',
                'before_or_equal:' . now(),
            ],
            'remarks' => ['sometimes'],
            'expiry_date' => ['required', 'date', 'after:today']
        ], [
            // 'quantity_received.max' => "You can only receive up to {$quantityToReceive} items for this order.",
            'received_date.before_or_equal' => "Received date field must be a date before or equal to current time"
        ]);

        // $order->order_status = OrderStatus::PARTIALLY_RECEIVED->value;
        // if ($totalOrderedQuantity == $totalQuantityReceived + $validated['quantity_received']) $order->order_status = OrderStatus::RECEIVED->value;

        DB::beginTransaction();
        $orderedItem->ordered_item_receive_dates()->create([
            'received_by_user_id' => Auth::user()->id,
            'quantity_received' => $validated['quantity_received'],
            'received_date' => $validated['received_date'],
            'expiry_date' => $validated['expiry_date'],
            'remarks' => $validated['remarks'],
        ]);
        $productInventoryStock->quantity += $validated['quantity_received'];
        $productInventoryStock->recently_added = $validated['quantity_received'];
        $orderedItem->quantity_received += $validated['quantity_received'];
        $productInventoryStock->save();
        $orderedItem->save();
        // $order->save();
        DB::commit();

        return redirect()->back();
    }

    public function addDeliveryReceiptNumber(Request $request)
    {
        $validated = $request->validate([
            'delivery_receipt_number' => ['required', 'unique:delivery_receipts,delivery_receipt_number'],
            'store_order_id' => ['required', 'exists:store_orders,id'],
            'remarks' => ['sometimes']
        ]);

        DeliveryReceipt::create([
            'delivery_receipt_number' => $validated['delivery_receipt_number'],
            'store_order_id' => $validated['store_order_id'],
            'remarks' => $validated['remarks']
        ]);
    }
}
