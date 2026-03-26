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
        $branchId = request('branchId');
        
        $branchOptions = $branches->toArray();
        
        if (is_null($branchId) || $branchId === 'all' || (is_array($branchId) && in_array('all', $branchId))) {
            $branchIds = collect($branchOptions)->pluck('value')->filter(fn($v) => $v !== 'all')->toArray();
        } else {
            $branchIds = is_array($branchId) ? $branchId : [$branchId];
        }
        
        $branchIds = array_map('intval', array_filter($branchIds, fn($v) => is_numeric($v)));

        $search = request('search');
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? 0;

        $query = ProductInventoryStockManager::with(['cost_center', 'product'])
            ->whereIn('store_branch_id', $branchIds)
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
            'filters' => request()->only(['time_period', 'branchId', 'search']),
            'timePeriods' => $timePeriods,
            'branches' => $branchOptions
        ]);
    }
}
