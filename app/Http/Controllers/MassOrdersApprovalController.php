<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Enum\OrderStatus;

class MassOrdersApprovalController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $branchIds = $user->store_branches->pluck('id');

        $suppliersForApproval = Supplier::forMassOrderApproval()->pluck('id');

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
        // A CPO order also carries its vendor and the invoice / receipt to check against.
        $order = StoreOrder::with('storeOrderItems.supplierItem', 'supplier', 'store_branch', 'cpoAttachments')->findOrFail($id);

        return Inertia::render('MassOrdersApproval/Show', [
            'order' => $order,
            // Why Approve and Reject are not offered on this order, or null while they are.
            // The page hides both on the same rule approve() and reject() enforce.
            'decisionProblem' => $this->decisionProblem($order),
            // CPO, AO, EO, ALOC: approving also commits the order and opens its receiving.
            'commitsOnApproval' => Supplier::isUnrestrictedTemplate($order->supplier?->supplier_code),
        ]);
    }

    /**
     * Why this order can no longer be approved or rejected, or null while it can.
     *
     * Only an order that is still pending, or approved with nothing committed or received
     * yet, may be decided. Approve used to run on any order: on a received one it put the
     * status back to approved / committed, and on a CPO order it also added a second set of
     * receiving rows that a delivery locked by Final Receive All could never receive.
     */
    private function decisionProblem(StoreOrder $order): ?string
    {
        $received = $order->receiving_finalized_at
            || $order->ordered_item_receive_dates()->whereIn('status', ['received', 'approved'])->exists();

        if ($received) {
            return "Order {$order->order_number} already has received items, so it can no longer be approved or rejected.";
        }

        $status = strtolower((string) $order->order_status);

        if (! in_array($status, [OrderStatus::PENDING->value, OrderStatus::APPROVED->value], true)) {
            return "Order {$order->order_number} is already ".str_replace('_', ' ', $status)
                .', so it can no longer be approved or rejected.';
        }

        return null;
    }

    /**
     * @throws ValidationException when the order can no longer be approved or rejected.
     */
    private function assertCanDecide(StoreOrder $order): void
    {
        if ($problem = $this->decisionProblem($order)) {
            throw ValidationException::withMessages(['error' => $problem]);
        }
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

            // Checked under the lock, so a store finalizing its delivery at the same moment
            // cannot slip in between this check and the approval.
            $this->assertCanDecide($order);

            // CPO, AO, EO and ALOC have no CS commit: approving commits them and opens receiving.
            $commitsOnApproval = Supplier::isUnrestrictedTemplate($order->supplier?->supplier_code);
            $now = Carbon::now();

            $order->order_status = $commitsOnApproval
                ? OrderStatus::COMMITTED->value
                : OrderStatus::APPROVED->value;
            $order->approver_id = Auth::id();
            $order->approval_action_date = $now;

            if ($commitsOnApproval) {
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

                if ($commitsOnApproval) {
                    $orderItem->quantity_commited = $approvedQuantity;
                    $orderItem->committed_by = Auth::id();
                    $orderItem->committed_date = $now;
                }

                $orderItem->save();

                if ($commitsOnApproval) {
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

            return $commitsOnApproval
                ? 'Order approved and committed successfully.'
                : 'Order approved successfully.';
        });

        return redirect()->route('mass-orders-approval.index')->with('success', $message);
    }

    public function reject(Request $request, $id)
    {
        DB::transaction(function () use ($id) {
            $order = StoreOrder::lockForUpdate()->findOrFail($id);

            $this->assertCanDecide($order);

            $order->order_status = OrderStatus::REJECTED->value;
            $order->save();
        });

        return redirect()->route('mass-orders-approval.index')->with('success', 'Order rejected successfully.');
    }
}
