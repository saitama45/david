<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderApprovalController extends Controller
{
    public function index()
    {
        $search = request('search');
        $filter = request('currentFilter') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        $counts = [
            'pending' => (clone $query)->where('order_request_status', 'pending')->count(),
            'approved' => (clone $query)->where('order_request_status', 'approved')->count(),
            'rejected' => (clone $query)->where('order_request_status', 'rejected')->count(),
        ];


        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        if ($filter)
            $query->where('order_request_status', $filter);

        $orders = $query->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('OrderApproval/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search', 'currentFilter']),
            'counts' => $counts
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();
        return Inertia::render('OrderApproval/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function approve(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'remarks' => ['sometimes'],
            'updatedOrderedItemDetails' => ['required']
        ]);

        DB::beginTransaction();
        $storeOrder = StoreOrder::findOrFail($validated['id']);
        $storeOrder->update([
            'order_request_status' => OrderRequestStatus::APRROVED->value,
            'approver_id' => Auth::user()->id,
            'approval_action_date' => Carbon::now()
        ]);
        foreach ($validated['updatedOrderedItemDetails'] as $item) {
            $orderedItem = StoreOrderItem::find($item['id']);
            $orderedItem->update([
                'total_cost' => $item['total_cost'],
                'quantity_approved' => $item['quantity_approved'],
            ]);
        }
        if (!empty($validated['remarks'])) {
            $storeOrder->store_order_remarks()->create([
                'user_id' => Auth::user()->id,
                'action' => 'approve order',
                'remarks' => $validated['remarks']
            ]);
        }

        DB::commit();
        return to_route('orders-approval.index');
    }

    public function reject(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'remarks' => ['sometimes']
        ]);
        $storeOrder = StoreOrder::findOrFail($validated['id']);
        $storeOrder->update([
            'order_request_status' => OrderRequestStatus::REJECTED->value,
            'approver_id' => Auth::user()->id,
            'approval_action_date' => Carbon::now()
        ]);
        if (!empty($validated['remarks'])) {
            $storeOrder->store_order_remarks()->create([
                'user_id' => Auth::user()->id,
                'action' => 'reject order',
                'remarks' => $validated['remarks']
            ]);
        }
        return to_route('orders-approval.index');
    }

    public function addRemarks($id)
    {
        dd($id);
    }
}
