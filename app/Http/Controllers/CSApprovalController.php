<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CSApprovalController extends Controller
{
    public function index()
    {
        $search = request('search');
        $filter = request('currentFilter') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        $query->where('manager_approval_status', 'approved');

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

        return Inertia::render('CSApproval/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search', 'currentFilter']),
            'counts' => $counts
        ]);
    }


    public function show($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();
        return Inertia::render('CSApproval/Show', [
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
            'commiter_id' => Auth::user()->id,
            'commited_action_date' => Carbon::now()
        ]);

        foreach ($validated['updatedOrderedItemDetails'] as $item) {
            $orderedItem = StoreOrderItem::find($item['id']);
            $orderedItem->update([
                'total_cost' => $item['total_cost'],
                'quantity_commited' => $item['quantity_approved'],
            ]);

            $orderedItem->ordered_item_receive_dates()->create([
                'received_by_user_id' => $storeOrder->encoder_id,
                'quantity_received' => $item['quantity_approved'],
            ]);
        }
        if (!empty($validated['remarks'])) {
            $storeOrder->store_order_remarks()->create([
                'user_id' => Auth::user()->id,
                'action' => 'cs approved order',
                'remarks' => $validated['remarks']
            ]);
        }

        DB::commit();
        return to_route('cs-approvals.index');
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
            'commiter_id' => Auth::user()->id,
            'commited_action_date' => Carbon::now()
        ]);
        if (!empty($validated['remarks'])) {
            $storeOrder->store_order_remarks()->create([
                'user_id' => Auth::user()->id,
                'action' => 'cs rejected order',
                'remarks' => $validated['remarks']
            ]);
        }
        return to_route('cs-approvals.index');
    }
}
