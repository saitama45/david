<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\ApprovedOrdersExport;
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
    public function index()
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->where('order_request_status', OrderRequestStatus::APRROVED->value);


        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        $orders = $query
            ->latest()
            ->paginate(10)
            ->withQueryString();



        return Inertia::render('OrderReceiving/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with([
            'encoder',
            'approver',
            'delivery_receipts',
            'store_branch',
            'supplier',
            'store_order_items',
            'store_order_remarks',
            'store_order_remarks.user',
            'ordered_item_receive_dates',
            'ordered_item_receive_dates.receiver',
            'ordered_item_receive_dates.store_order_item',
            'ordered_item_receive_dates.store_order_item.product_inventory',
            'image_attachments'
        ])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();


        $images = $order->image_attachments->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->file_path),
            ];
        });

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

    public function receive(Request $request, $id)
    {
        $orderedItem = StoreOrderItem::with('store_order')->findOrFail($id);


        $validated = $request->validate([
            'quantity_received' => [
                'required',
                'numeric',
                'min:1',
            ],
            'received_date' => [
                'required',
                'date_format:Y-m-d\TH:i',
                'before_or_equal:' . now(),
            ],
            'remarks' => ['sometimes'],
            'expiry_date' => ['required', 'date', 'after:today']
        ], [
            'received_date.before_or_equal' => "Received date field must be a date before or equal to current time"
        ]);

        DB::beginTransaction();
        $orderedItem->ordered_item_receive_dates()->create([
            'received_by_user_id' => Auth::user()->id,
            'quantity_received' => $validated['quantity_received'],
            'received_date' => Carbon::parse($validated['received_date'])->format('Y-m-d H:i:s'),
            'expiry_date' => Carbon::parse($validated['expiry_date'])->format('Y-m-d'),
            'remarks' => $validated['remarks'],
        ]);

        $orderedItem->save();

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

    public function updateDeliveryReceiptNumber(Request $request, $id)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'delivery_receipt_number' => ['required'],
            'store_order_id' => ['required', 'exists:store_orders,id'],
            'remarks' => ['sometimes']
        ]);

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

    public function updateReceiveDateHistory(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'quantity_received' => ['required', 'numeric', 'min:1'],
            'expiry_date' => ['required']
        ]);

        $history = OrderedItemReceiveDate::findOrFail($validated['id']);
        $history->update($validated);
        return redirect()->back();
    }
}
