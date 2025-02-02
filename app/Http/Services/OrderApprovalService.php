<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderApprovalService extends StoreOrderService
{
    public function getOrdersAndCounts()
    {
        $search = request('search');
        $filter = request('currentFilter') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);
        $counts = $this->getCounts($query);
        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        if ($filter)
            $query->where('manager_approval_status', $filter);

        return [
            'orders' => $query->latest()
                ->paginate(10)
                ->withQueryString(),
            'counts' => $counts
        ];
    }

    public function getCounts($query)
    {
        return [
            'pending' => (clone $query)->where('manager_approval_status', 'pending')->count(),
            'approved' => (clone $query)->where('manager_approval_status', 'approved')->count(),
            'rejected' => (clone $query)->where('manager_approval_status', 'rejected')->count(),
        ];
    }

    public function approveOrder(array $data)
    {
        DB::beginTransaction();
        $storeOrder = StoreOrder::findOrFail($data['id']);
        $storeOrder->update([
            'manager_approval_status' => OrderRequestStatus::APRROVED->value,
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

        $this->addRemarks($storeOrder, $data['remarks']);

        DB::commit();
    }

    public function addRemarks($storeOrder, $remarks)
    {
        if (!empty($remarks)) {
            $storeOrder->store_order_remarks()->create([
                'user_id' => Auth::user()->id,
                'action' => 'manager rejected order',
                'remarks' => $remarks
            ]);
        }
    }
}
