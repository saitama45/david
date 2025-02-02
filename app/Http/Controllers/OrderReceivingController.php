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
        $this->orderReceivingService->receiveOrder($id, $request->validated);
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
}
