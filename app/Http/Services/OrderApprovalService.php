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
    public function getOrdersAndCounts($page = 'manager', $condition = null, $variant  = null)
    {
        $search = request('search');
        $filter = $page == 'manager' ? request('currentFilter') ?? 'pending' : 'approved';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        if ($variant != null) {
            $query->where('variant', $variant);
        }


        $counts = $this->getCounts($query, $condition);
        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');



        if ($filter)
            $query->where('order_status', $filter);



        return [
            'orders' => $query->latest()
                ->paginate(10)
                ->withQueryString(),
            'counts' => $counts
        ];
    }

    public function getCounts($query, $condition)
    {
        return [
            'pending' => (clone $query)->where('order_status', 'pending')->count(),
            'approved' => (clone $query)->where('order_status', 'approved')->count(),
            'rejected' => (clone $query)->where('order_status', 'rejected')->count(),
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
        }
        $this->addRemarks($storeOrder, $data['remarks']);
        DB::commit();
    }

    public function rejectOrder(array $data)
    {
        DB::beginTransaction();

        $storeOrder = StoreOrder::findOrFail($data['id']);

        $storeOrder->update([
            'order_status' => OrderRequestStatus::REJECTED->value,
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
