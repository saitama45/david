<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class CostOfGoodController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $search = request('search');
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? $timePeriods[1];

        $query = ProductInventoryStockManager::with(['cost_center', 'product'])
            ->where('store_branch_id', $branchId)
            ->where('total_cost', '<', 0);

        if ($time_period != 0) {
            $query->whereMonth('transaction_date', $time_period);
        } else {
            $query->whereYear('transaction_date', Carbon::today()->year);
        }

        $costOfGoods = $query->latest()
            ->paginate(10);
        return Inertia::render('CostOfGood/Index', [
            'costOfGoods' => $costOfGoods,
            'filters' => request()->only(['from', 'to', 'branchId', 'search', 'time_period']),
            'timePeriods' => $timePeriods,
            'branches' => $branches
        ]);
    }
}
