<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Enum\OrderStatus;

class MassOrdersApprovalController extends Controller
{
    public function index(Request $request)
    {
        $suppliersForApproval = Supplier::where('is_forapproval_massorders', true)->pluck('id');

        $query = StoreOrder::with(['supplier', 'store_branch'])
            ->where('variant', 'mass regular')
            ->whereIn('supplier_id', $suppliersForApproval);

        $filter = $request->input('filter', 'pending');

        if ($filter !== 'all') {
            $query->where('order_status', $filter);
        }

        $orders = $query->latest()->paginate(15)->withQueryString();

        $counts = [
            'all' => StoreOrder::where('variant', 'mass regular')->whereIn('supplier_id', $suppliersForApproval)->count(),
            'pending' => StoreOrder::where('variant', 'mass regular')->whereIn('supplier_id', $suppliersForApproval)->where('order_status', 'pending')->count(),
            'approved' => StoreOrder::where('variant', 'mass regular')->whereIn('supplier_id', $suppliersForApproval)->where('order_status', 'approved')->count(),
        ];

        return Inertia::render('MassOrdersApproval/Index', [
            'orders' => $orders,
            'counts' => $counts,
            'filters' => ['currentFilter' => $filter],
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with('storeOrderItems.supplierItem', 'supplier', 'store_branch')->findOrFail($id);

        return Inertia::render('MassOrdersApproval/Show', [
            'order' => $order,
        ]);
    }

    public function approve(Request $request, $id)
    {
        $order = StoreOrder::findOrFail($id);
        $order->order_status = OrderStatus::APPROVED->value;
        $order->save();

        foreach ($request->items as $item) {
            $orderItem = $order->storeOrderItems()->find($item['id']);
            if ($orderItem) {
                $orderItem->quantity_approved = $item['quantity_approved'];
                $orderItem->save();
            }
        }

        return redirect()->route('mass-orders-approval.index')->with('success', 'Order approved successfully.');
    }

    public function reject(Request $request, $id)
    {
        $order = StoreOrder::findOrFail($id);
        $order->order_status = OrderStatus::REJECTED->value;
        $order->save();

        return redirect()->route('mass-orders-approval.index')->with('success', 'Order rejected successfully.');
    }
}
