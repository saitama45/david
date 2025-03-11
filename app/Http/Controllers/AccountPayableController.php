<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountPayableController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $search = request('search');
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? $timePeriods[1];

        $query = StoreOrderItem::with(['store_order', 'product_inventory'])
            ->where('quantity_received', '>', 0)
            ->whereHas('store_order', function ($query) use ($branchId, $time_period) {
                $query->where('store_branch_id', $branchId);
                $query->whereIn('order_status', ['received', 'incomplete']);

                if ($time_period != 0) {
                    $query->whereMonth('order_date', $time_period);
                } else {
                    $query->whereYear('order_date', Carbon::today()->year);
                }
            });

        $storeOrderItems = $query->latest()
            ->paginate(10);

        return Inertia::render('AccountPayable/Index', [
            'storeOrderItems' => $storeOrderItems,
            'branches' => $branches,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'timePeriods' => $timePeriods
        ]);
    }
}
