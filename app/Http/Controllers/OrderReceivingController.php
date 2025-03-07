<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\ApprovedOrdersExport;
use App\Http\Requests\OrderReceiving\AddDeliveryReceiptNumberRequest;
use App\Http\Requests\OrderReceiving\ReceiveOrderRequest;
use App\Http\Requests\OrderReceiving\UpdateDeliveryReceiptNumberRequest;
use App\Http\Requests\OrderReceiving\UpdateReceiveDateHistoryRequest;
use App\Http\Services\OrderReceivingService;
use App\Models\DeliveryReceipt;
use App\Models\OrderedItemReceiveDate;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class OrderReceivingController extends Controller
{
    protected $orderReceivingService;

    public function __construct(OrderReceivingService $orderReceivingService)
    {
        $this->orderReceivingService = $orderReceivingService;
    }
    public function index()
    {
        $orders = $this->orderReceivingService->getOrdersList();
        return Inertia::render('OrderReceiving/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $order = $this->orderReceivingService->getOrderDetails($id);
        $images = $this->orderReceivingService->getImageAttachments($order);
        $orderedItems = $this->orderReceivingService->getOrderItems($order);
        $receiveDatesHistory = $order->ordered_item_receive_dates;

        return Inertia::render('OrderReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images
        ]);
    }

    public function export()
    {
        $search = request('search');

        return Excel::download(
            new ApprovedOrdersExport($search),
            'approved-orders-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function receive(ReceiveOrderRequest $request, $id)
    {
        $this->orderReceivingService->receiveOrder($id, $request->validated());
        return redirect()->back();
    }

    public function addDeliveryReceiptNumber(AddDeliveryReceiptNumberRequest $request)
    {
        $validated = $request->validated();
        DeliveryReceipt::create([
            'delivery_receipt_number' => $validated['delivery_receipt_number'],
            'store_order_id' => $validated['store_order_id'],
            'remarks' => $validated['remarks']
        ]);
    }

    public function updateDeliveryReceiptNumber(UpdateDeliveryReceiptNumberRequest $request, $id)
    {
        $validated = $request->validated();

        $id = $validated['id'];
        unset($validated['id']);

        $receipt = DeliveryReceipt::findOrFail($id);

        $receipt->update($validated);

        return redirect()->back();
    }

    public function destroyDeliveryReceiptNumber($id)
    {
        $receipt = DeliveryReceipt::findOrFail($id);
        $receipt->delete();

        return redirect()->back();
    }

    public function deleteReceiveDateHistory($id)
    {
        $history = OrderedItemReceiveDate::with('store_order_item')->findOrFail($id);
        DB::beginTransaction();
        $history->delete();
        DB::commit();

        return redirect()->back();
    }

    public function updateReceiveDateHistory(UpdateReceiveDateHistoryRequest $request)
    {
        $validated = $request->validated();
        $history = OrderedItemReceiveDate::findOrFail($validated['id']);
        $history->update($validated);
        return redirect()->back();
    }

    public function confirmReceive($id)
    {
        $history = OrderedItemReceiveDate::with(['store_order_item.store_order.store_order_items', 'store_order_item.product_inventory'])
            ->whereHas('store_order_item.store_order', function ($query) use ($id) {
                $query->where('id', $id);
            })
            ->where('status', 'pending')
            ->get();
      
        foreach ($history as $data) {
            DB::beginTransaction();
            $this->extracted($data);
            $data->store_order_item->save();
            $data->save();
            $this->getOrderStatus($id);
            DB::commit();
        }

        return back();
    }

    public function extracted($data): void
    {
        $data->update([
            'status' => 'approved',
            'approval_action_by' => Auth::user()->id,
            'received_by_user_id' => Auth::user()->id,
        ]);

        $item = $data->store_order_item->product_inventory;
        $storeOrder = $data->store_order_item->store_order;


        $stock = ProductInventoryStock::where('product_inventory_id', $item->id)->where('store_branch_id', $storeOrder->store_branch_id);
        $stock->increment('quantity', $data->quantity_received);
        $stock->update(['recently_added' => $data->quantity_received]);

        ProductInventoryStockManager::create([
            'product_inventory_id' => $item->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'quantity' => $data->quantity_received,
            'action' => 'add_quantity',
            'remarks' => 'From newly received items. (Order Number: ' . $storeOrder->order_number . ')'
        ]);

        $data->store_order_item->quantity_received += $data->quantity_received;
        $item->save();
    }

    public function getOrderStatus($id)
    {
        $storeOrder = StoreOrder::with('store_order_items')->find($id);

        $orderedItems = $storeOrder->store_order_items;

        $storeOrder->order_status = OrderStatus::RECEIVED->value;
        foreach ($orderedItems as $itemOrdered) {
            if ($itemOrdered->quantity_commited > $itemOrdered->quantity_received) {
                $storeOrder->order_status = OrderStatus::INCOMPLETE->value;
            }
        }

        $storeOrder->save();
    }
}
