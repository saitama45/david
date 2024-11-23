<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Models\Order;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderApprovalController extends Controller
{
    public function index()
    {
        $search = request('search');
        $filter = request('filter') ?? 'pending';

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
            ->paginate(10);

        return Inertia::render('OrderApproval/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search', 'filter']),
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

    public function approve($id)
    {
        StoreOrder::findOrFail($id)->update([
            'order_request_status' => OrderRequestStatus::APRROVED->value,
            'approver_id' => Auth::user()->id,
            'approval_action_date' => Carbon::now()
        ]);
        return back();
    }

    public function reject($id)
    {
        StoreOrder::findOrFail($id)->update([
            'order_request_status' => OrderRequestStatus::REJECTED->value,
            'approver_id' => Auth::user()->id,
            'approval_action_date' => Carbon::now()
        ]);
        return back();
    }
}
