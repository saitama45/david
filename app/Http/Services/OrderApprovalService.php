<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderApprovalService extends StoreOrderService
{
    /**
     * Get orders and their counts for Order Approval (SM).
     *
     * @param string $page This parameter is typically 'manager' for this context.
     * @param mixed $condition Not used in this specific implementation.
     * @param mixed $variant Used for additional query filtering (e.g., 'variant' column).
     * @param string $currentFilter NEW: The current status filter from the UI ('pending', 'rejected', or 'all').
     * @return array Contains 'orders' (paginated) and 'counts'.
     */
    public function getOrdersAndCounts($page = 'manager', $condition = null, $variant = null, $currentFilter = 'pending')
    {
        $search = request('search');

        // Start with a base query that includes relationships and potential variant filter
        $baseQuery = StoreOrder::query()->with(['store_branch', 'supplier']);

        if ($variant != null) {
            $baseQuery->where('variant', $variant);
        }

        if ($search) {
            $baseQuery->where('order_number', 'like', '%' . $search . '%')
                      ->orWhereHas('supplier', function ($q) use ($search) {
                          $q->where('name', 'like', '%' . $search . '%');
                      })
                      ->orWhereHas('store_branch', function ($q) use ($search) {
                          $q->where('name', 'like', '%' . $search . '%');
                      });
        }

        // Calculate counts based on the base query (before applying the specific status filter for the main list)
        $counts = $this->getCounts($baseQuery);

        // Apply the specific status filter for the main orders list
        if ($currentFilter === 'all') {
            $baseQuery->where('order_status', OrderStatus::APPROVED->value); // "All" tab means show approved orders
        } else {
            $baseQuery->where('order_status', $currentFilter); // Use pending or rejected
        }

        return [
            'orders' => $baseQuery->latest()
                ->paginate(10)
                ->withQueryString(),
            'counts' => $counts
        ];
    }

    /**
     * Calculates counts for different order statuses based on a base query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $baseQuery A query builder instance without specific status filters yet.
     * @return array
     */
    public function getCounts($baseQuery)
    {
        return [
            'pending' => (clone $baseQuery)->where('order_status', OrderStatus::PENDING->value)->count(),
            'approved' => (clone $baseQuery)->where('order_status', OrderStatus::APPROVED->value)->count(),
            'rejected' => (clone $baseQuery)->where('order_status', OrderStatus::REJECTED->value)->count(),
        ];
    }

    /**
     * Get order items for a given store order, eager loading necessary relationships.
     *
     * @param StoreOrder $order The store order model.
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getOrderItems(StoreOrder $order)
    {
        // Eager load the supplierItem relationship and ensure 'cost' is selected.
        // Also ensure 'ItemCode', 'item_name', and 'uom' are selected for the frontend.
        return $order->store_order_items()->with(['supplierItem' => function($query) {
            $query->select('id', 'ItemCode', 'item_name', 'uom', 'cost');
        }])->get();
    }


    public function approveOrder(array $data)
    {
        DB::beginTransaction();
        $storeOrder = StoreOrder::findOrFail($data['id']);
        $storeOrder->update([
            'order_status' => OrderStatus::APPROVED->value,
            'approver_id' => Auth::user()->id,
            'approval_action_date' => Carbon::now()
        ]);

        foreach ($data['updatedOrderedItemDetails'] as $item) {
            $orderedItem = StoreOrderItem::find($item['id']);
            $orderedItem->update([
                'total_cost' => $item['total_cost'],
                'quantity_approved' => $item['quantity_approved'],
            ]);

            $orderedItem->ordered_item_receive_dates()->create([
                'received_by_user_id' => $storeOrder->encoder_id,
                'quantity_received' => $item['quantity_approved'],
            ]);
        }
        $this->addRemarks($storeOrder, $data['remarks']);
        DB::commit();
    }

    public function rejectOrder(array $data)
    {
        DB::beginTransaction();

        $storeOrder = StoreOrder::findOrFail($data['id']);

        $storeOrder->update([
            'order_status' => OrderRequestStatus::REJECTED->value, // Assuming OrderRequestStatus is used for rejection
            'approver_id' => Auth::user()->id,
            'approval_action_date' => Carbon::now()
        ]);

        $this->addRemarks($storeOrder, $data['remarks'], 'reject');

        DB::commit();
    }

    public function addRemarks($storeOrder, $remarks, $action = 'approve')
    {
        if (!empty($remarks)) {
            $storeOrder->store_order_remarks()->create([
                'user_id' => Auth::user()->id,
                'action' => $action,
                'remarks' => $remarks
            ]);
        }
    }
}
