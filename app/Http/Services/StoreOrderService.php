<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoreOrderService
{
    public function getOrderNumber($id)
    {
        $branchId = $id;
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;
        while (true) {
            $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
            $store_order_number = "$branchCode-$orderNumber";
            $result = StoreOrder::where('order_number', $store_order_number)->first();
            $orderCount++;
            if (!$result) break;
        }
        return $store_order_number;
    }

    public function getOrdersList()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $branchId = request('branchId');
        $search = request('search');
        $filterQuery = request('filterQuery') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($filterQuery !== 'all')
            $query->where('order_request_status', $filterQuery);

        if ($branchId)
            $query->where('store_branch_id', $branchId);


        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%')
                ->orWhereHas('store_branch', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                });

        return $query
            ->where('variant', 'regular')
            ->latest()
            ->paginate(10)
            ->withQueryString();
    }

    public function createStoreOrder(array $data)
    {
        DB::beginTransaction();
        $order = StoreOrder::create([
            'encoder_id' => Auth::user()->id,
            'supplier_id' => $data['supplier_id'],
            'store_branch_id' => $data['branch_id'],
            'order_number' => $this->getOrderNumber($data['branch_id']),
            'order_date' => Carbon::parse($data['order_date'])->addDays(1)->format('Y-m-d'),
            'order_status' => OrderStatus::PENDING->value,
            'order_request_status' => OrderRequestStatus::PENDING->value,
        ]);

        foreach ($data['orders'] as $data) {
            $order->store_order_items()->create([
                'product_inventory_id' => $data['id'],
                'quantity_ordered' => $data['quantity'],
                'total_cost' => $data['total_cost'],
            ]);
        }
        DB::commit();
    }

    public function getPreviousOrderReference()
    {
        if (request()->has('orderId')) {
            $orderId = request()->input('orderId');
            return StoreOrder::with(['store_order_items', 'store_order_items.product_inventory', 'store_order_items.product_inventory.unit_of_measurement'])->find($orderId);
        }

        return null;
    }

    public function getOrderDetails($id)
    {
        return StoreOrder::with([
            'encoder',
            'approver',
            'commiter',
            'delivery_receipts',
            'store_branch',
            'supplier',
            'store_order_items',
            'store_order_remarks',
            'store_order_remarks.user',
            'ordered_item_receive_dates',
            'ordered_item_receive_dates.receiver',
            'ordered_item_receive_dates.store_order_item',
            'ordered_item_receive_dates.store_order_item.product_inventory',
            'image_attachments' => function ($query) {
                $query->where('is_approved', true);
            },
        ])->where('order_number', $id)->firstOrFail();
    }

    public function getOrderItems(StoreOrder $order)
    {
        return $order->store_order_items()->with(['product_inventory.unit_of_measurement'])->get();
    }

    public function getImageAttachments(StoreOrder  $order)
    {
        return $order->image_attachments->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->file_path),
            ];
        });
    }

    public function getOrder($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])
            ->where('order_number', $id)->firstOrFail();

        if ($order->order_request_status !== OrderRequestStatus::PENDING->value)
            abort(401, 'Order can no longer be updated');

        return $order;
    }

    public function updateOrder(StoreOrder $order, array $data)
    {
        DB::beginTransaction();

        if ($order->store_branch_id != $data['branch_id'])
            $order->order_number = $this->getOrderNumber($data['branch_id']);


        $order->update([
            'supplier_id' => $data['supplier_id'],
            'store_branch_id' => $data['branch_id'],
            'order_date' => Carbon::parse($data['order_date'])->addDays(1)->format('Y-m-d'),
        ]);

        $updatedProductIds = collect($data['orders'])->pluck('id')->toArray();

        $order->store_order_items()
            ->whereNotIn('product_inventory_id', $updatedProductIds)
            ->delete();

        foreach ($data['orders'] as $data) {
            $order->store_order_items()->updateOrCreate(
                [
                    'store_order_id' => $order->id,
                    'product_inventory_id' => $data['id'],
                ],
                [
                    'quantity_ordered' => $data['quantity'],
                    'total_cost' => $data['total_cost'],
                ]
            );
        }

        $order->save();
        DB::commit();
    }
}
