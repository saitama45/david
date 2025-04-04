<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UpcomingInventoryController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? 0;

        $query = StoreOrder::query()
            ->with(['store_order_items', 'supplier', 'store_branch'])
            ->where('order_status', 'commited');

        $query->where('store_branch_id', $branchId);

        if ($time_period != 0) {
            $query->whereMonth('order_date', $time_period);
        } else {
            $query->whereYear('order_date', Carbon::today()->year);
        }

        $inventories = $query->latest()
            ->paginate(10)
            ->through(function ($order) {
                return [
                    'order_number' => $order->order_number,
                    'branch' => $order->store_branch->name,
                    'supplier' => $order->supplier->name,
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total' =>  number_format($order->store_order_items->map(function ($item) {
                        return $item->cost_per_quantity * $item->quantity_commited;
                    })->sum(), 2, '.', ','),
                ];
            });


        return Inertia::render('UpcomingInventories/Index', [
            'inventories' => $inventories,
            'branches' => $branches,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'timePeriods' => $timePeriods
        ]);
    }
    // public function index()
    // {
    //     $branches = StoreBranch::options();
    //     $branchId = request('branchId') ?? $branches->keys()->first();
    //     $timePeriods = TimePeriod::values();
    //     $time_period = request('time_period') ?? $timePeriods[1];

    //     $query = StoreOrderItem::with(['store_order', 'product_inventory'])
    //         ->whereHas('store_order', function ($query) use ($branchId, $time_period) {
    //             $query->where('store_branch_id', $branchId);
    //             $query->where('order_status', 'commited');

    //             if ($time_period != 0) {
    //                 $query->whereMonth('order_date', $time_period);
    //             } else {
    //                 $query->whereYear('order_date', Carbon::today()->year);
    //             }
    //         });

    //     $inventories = $query->latest()
    //         ->paginate(10);

    //     return Inertia::render('UpcomingInventories/Index', [
    //         'inventories' => $inventories,
    //         'branches' => $branches,
    //         'filters' => request()->only(['from', 'to', 'branchId', 'search']),
    //         'timePeriods' => $timePeriods
    //     ]);
    // }
}
