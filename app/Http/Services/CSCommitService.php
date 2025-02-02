<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CSCommitService extends OrderApprovalService
{
    public function commitOrder(array $data)
    {
        DB::beginTransaction();
        $storeOrder = StoreOrder::findOrFail($data['id']);
        $storeOrder->update([
            'order_request_status' => OrderRequestStatus::APRROVED->value,
            'commiter_id' => Auth::user()->id,
            'commited_action_date' => Carbon::now()
        ]);

        foreach ($data['updatedOrderedItemDetails'] as $item) {
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
        $this->addRemarks($storeOrder, $data['remarks']);

        DB::commit();
    }

}
