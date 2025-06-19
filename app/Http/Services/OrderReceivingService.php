<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderReceivingService extends StoreOrderService
{
    public function getOrdersList($variant = 'regular')
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->whereNotIn('order_status', ['pending', 'rejected', 'approved']);
        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        return $query
            ->latest()
            ->paginate(10)
            ->withQueryString();
    }

    public function receiveOrder($id, array $data)
    {
        $orderedItem = StoreOrderItem::with('store_order')->findOrFail($id);

        DB::beginTransaction();
        $orderedItem->ordered_item_receive_dates()->create([
            'received_by_user_id' => Auth::user()->id,
            'quantity_received' => $data['quantity_received'],
            'received_date' => Carbon::parse($data['received_date'])->format('Y-m-d H:i:s'),
            'expiry_date' => Carbon::parse($data['expiry_date'])->format('Y-m-d'),
            'remarks' => $data['remarks'],
        ]);
        $orderedItem->save();
        DB::commit();
    }
}
