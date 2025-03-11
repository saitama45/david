<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\StoreBranch;
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
        $time_period = request('time_period') ?? $timePeriods[1];

        $query = StoreOrderItem::with(['store_order', 'product_inventory'])
            ->whereHas('store_order', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
                $query->where('order_status', 'commited');
            });

        // if ($time_period != 0) {
        //     $query->whereMonth('store_orders.order_date', $time_period);
        // } else {
        //     $query->whereYear('store_orders.order_date', Carbon::today()->year);
        // }

        $inventories = $query->latest()
            ->paginate(10);

        return Inertia::render('UpcomingInventories/Index', [
            'inventories' => $inventories,
            'branches' => $branches,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'timePeriods' => $timePeriods
        ]);
    }
}
