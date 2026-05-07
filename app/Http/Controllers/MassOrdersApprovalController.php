<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Enum\OrderStatus;

class MassOrdersApprovalController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $branchIds = $user->store_branches->pluck('id');

        $suppliersForApproval = Supplier::where('is_forapproval_massorders', true)->pluck('id');

        $query = StoreOrder::with(['supplier', 'store_branch'])
            ->where('variant', 'mass regular')
            ->whereIn('supplier_id', $suppliersForApproval)
            ->whereIn('store_branch_id', $branchIds);

        $filter = $request->input('filter', 'pending');

        if ($filter !== 'all') {
            $query->where('order_status', $filter);
        }

        $orders = $query->latest()->paginate(15)->withQueryString();

        $counts = [
            'all' => StoreOrder::where('variant', 'mass regular')->whereIn('supplier_id', $suppliersForApproval)->whereIn('store_branch_id', $branchIds)->count(),
            'pending' => StoreOrder::where('variant', 'mass regular')->whereIn('supplier_id', $suppliersForApproval)->where('order_status', 'pending')->whereIn('store_branch_id', $branchIds)->count(),
            'approved' => StoreOrder::where('variant', 'mass regular')->whereIn('supplier_id', $suppliersForApproval)->where('order_status', 'approved')->whereIn('store_branch_id', $branchIds)->count(),
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
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_approved' => ['required', 'numeric', 'min:0'],
        ]);

        $message = DB::transaction(function () use ($id, $validated) {
            $order = StoreOrder::with('supplier')->lockForUpdate()->findOrFail($id);
            $isCpoOrder = strtoupper(trim((string) $order->supplier?->supplier_code)) === 'CPO';
            $now = Carbon::now();

            $order->order_status = $isCpoOrder
                ? OrderStatus::COMMITTED->value
                : OrderStatus::APPROVED->value;
            $order->approver_id = Auth::id();
            $order->approval_action_date = $now;

            if ($isCpoOrder) {
                $order->commiter_id = Auth::id();
                $order->commited_action_date = $now;
            }

            $order->save();

            foreach ($validated['items'] as $item) {
                $orderItem = $order->storeOrderItems()->find($item['id']);

                if (!$orderItem) {
                    continue;
                }

                $approvedQuantity = $item['quantity_approved'];
                $orderItem->quantity_approved = $approvedQuantity;

                if ($isCpoOrder) {
                    $orderItem->quantity_commited = $approvedQuantity;
                    $orderItem->committed_by = Auth::id();
                    $orderItem->committed_date = $now;
                }

                $orderItem->save();

                if ($isCpoOrder) {
                    $orderItem->ordered_item_receive_dates()->updateOrCreate(
                        ['status' => 'pending'],
                        [
                            'received_by_user_id' => Auth::id(),
                            'quantity_received' => $approvedQuantity,
                            'received_date' => null,
                            'remarks' => null,
                        ]
                    );
                }
            }

            return $isCpoOrder
                ? 'Order approved and committed successfully.'
                : 'Order approved successfully.';
        });

        return redirect()->route('mass-orders-approval.index')->with('success', $message);
    }

    public function reject(Request $request, $id)
    {
        $order = StoreOrder::findOrFail($id);
        $order->order_status = OrderStatus::REJECTED->value;
        $order->save();

        return redirect()->route('mass-orders-approval.index')->with('success', 'Order rejected successfully.');
    }
}
