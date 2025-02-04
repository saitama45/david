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
    public function getOrdersAndCounts($condition = null)
    {
        $condition = $condition ?: 'manager_approval_status';
        $search = request('search');
        $filter = request('currentFilter') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        if ($condition != 'manager_approval_status')
            $query->where('order_status', 'approved');

        $counts = $this->getCounts($query, $condition);
        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        if ($filter)
            $query->where($condition, $filter);

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
            'pending' => (clone $query)->where($condition, 'pending')->count(),
            'approved' => (clone $query)->where($condition, 'approved')->count(),
            'rejected' => (clone $query)->where($condition, 'rejected')->count(),
        ];
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
            'manager_approval_status' => OrderRequestStatus::REJECTED->value,
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
