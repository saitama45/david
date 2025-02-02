<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\ProductInventory;
use App\Models\StoreOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DTSStoreOrderService extends StoreOrderService
{

    public function createOrder(array $data)
    {
        DB::beginTransaction();
        $order = StoreOrder::create([
            'encoder_id' => 1,
            'supplier_id' => $data['supplier_id'],
            'store_branch_id' => $data['branch_id'],
            'order_number' => $this->getOrderNumber($data['branch_id']),
            'order_date' => Carbon::parse($data['order_date'])->addDay()->format('Y-m-d'),
            'order_status' => OrderStatus::PENDING->value,
            'order_request_status' => OrderRequestStatus::PENDING->value,
            'variant' => $data['variant']
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

    public function getItems($variant)
    {
        if ($variant === 'fruits and vegetables') {
            return ProductInventory::where('inventory_category_id', 6)
                ->options();
        } else if ($variant === 'salmon') {
            return ProductInventory::where('inventory_code', '269A2A')->options();
        } else {
            return ProductInventory::where('inventory_code', '359A2A')->options();
        }
    }

    public function getDtsOrdersList()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $search = request('search');
        $filter = request('filterQuery') ?? 'pending';
        $branchId = request('branchId');



        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->whereNot('variant', 'regular');

        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);
        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($filter !== 'all')

            $query->where('order_request_status', $filter);

        if ($branchId)
            $query->where('store_branch_id', $branchId);




        return $query
            ->latest()
            ->paginate(10)
            ->withQueryString();
    }
}
