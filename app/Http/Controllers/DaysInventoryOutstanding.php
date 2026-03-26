<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DaysInventoryOutstanding extends Controller
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

        $chart_time_period = request('chart_time_period') ?? 0;

        $begginingInventory = $this->getBeginningInventory($branchIds);
        $endingInventory = $this->getEndingInventory($branchIds);

        $cogsAll = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));

        $averageInventory = ($begginingInventory + $endingInventory) / 2;

        return Inertia::render('DaysInventoryOutstanding/Index', [
            'filters' => request()->only(['branchId', 'search', 'chart_time_period']),
            'branches' => $branchOptions,
            'begginingInventory' => number_format($begginingInventory, 2, '.', ','),
            'endingInventory' => number_format($endingInventory, 2, '.', ','),
            'costOfGoods' => number_format($cogsAll, 2, '.', ','),
            'averageInventory' => number_format($averageInventory, 2, '.', ','),
            'daysInventoryOutstanding' => number_format($this->getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period), 2, '.', ','),
        ]);
    }

    public function getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period)
    {
        return $cogsAll > 0 ? ($averageInventory / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getEndingInventory($branchIds)
    {
        return ProductInventoryStockManager::query()
            ->whereIn('store_branch_id', (array)$branchIds)
            ->sum('total_cost');
    }

    public function getBeginningInventory($branchIds)
    {
        $branchIds = (array)$branchIds;
        
        $firstTransactionIds = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('quantity', '>', 0)
            ->selectRaw('MIN(id) as id')
            ->groupBy('product_inventory_id')
            ->pluck('id');

        return ProductInventoryStockManager::whereIn('id', $firstTransactionIds)
            ->sum('total_cost');
    }


    // public function index()
    // {
    //     $branches = StoreBranch::options();
    //     $branchId = request('branchId') ?? $branches->keys()->first();
    //     $search = request('search');
    //     $query = ProductInventoryStockManager::query()->with('product')
    //         ->where('store_branch_id', $branchId);

    //     if ($search) {
    //         $query->whereHas('product', function ($query) use ($search) {
    //             $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
    //         });
    //     }

    //     $items = $query->paginate(10)->withQueryString();

    //     return Inertia::render('DaysInventoryOutstanding/Index', [
    //         'items' => $items,
    //         'branches' => $branches,
    //         'filters' => request()->only(['branchId', 'search']),
    //     ]);
    // }
}
