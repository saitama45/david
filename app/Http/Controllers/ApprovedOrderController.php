<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\ApprovedOrdersExport;
use App\Exports\ApprovedReceivedItemsExport;
use App\Models\OrderedItemReceiveDate;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as FacadesExcel;

class ApprovedOrderController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier', 'ordered_item_receive_dates' => function ($query) {
            $query->where('status', 'approved');
        }])->whereHas('ordered_item_receive_dates', function ($query) {
            $query->where('status', 'approved');
        });

        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        $orders = $query
            ->latest()
            ->paginate(10);

        return Inertia::render('ApprovedReceivedItem/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::where('order_number', $id)->firstOrFail();
        $items = $order->ordered_item_receive_dates()->with('store_order_item.product_inventory')->where('status', 'approved')->paginate(10);

        return Inertia::render('ApprovedReceivedItem/Show', [
            'order' => $order,
            'items' => $items
        ]);
    }

    public function export()
    {
        $search = request('search');


        return FacadesExcel::download(
            new ApprovedReceivedItemsExport($search),
            'approved-orders-' . now()->format('Y-m-d') . '.xlsx'
        );
    }


    public function cancelApproveStatus(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'remarks' => ['required']
        ]);

        DB::beginTransaction();
        $order = OrderedItemReceiveDate::with(['store_order_item', 'store_order_item.store_order'])->findOrFail($validated['id']);
        $order->update([
            'status' => 'pending',
        ]);
        $item = $order->store_order_item;
        $item->update([
            'quantity_received' => $item->quantity_received - $order->quantity_received,
        ]);
        $item->store_order->store_order_remarks()->create([
            'user_id' => Auth::id(),
            'action' => "Cancelled Approved Status for Received Item Request (ID: $order->id)",
            'remarks' => $validated['remarks']
        ]);
        $this->getOrderStatus($order);
        DB::commit();

        return back();
    }

    public function getOrderStatus($data)
    {
        $storeOrder = StoreOrder::with('store_order_items')->find($data->store_order_item->store_order_id);
        $orderedItems = $storeOrder->store_order_items;
        $receivedCount = 0;
        $storeOrder->order_status = OrderStatus::RECEIVED->value;
        foreach ($orderedItems as $itemOrdered) {
            $receivedCount += $itemOrdered->quantity_received;
            if ($itemOrdered->quantity_approved > $itemOrdered->quantity_received) {
                $storeOrder->order_status = OrderStatus::INCOMPLETE->value;
            }
        }
        if ($receivedCount < 1) $storeOrder->order_status = OrderStatus::PENDING->value;

        $storeOrder->save();
    }
}
