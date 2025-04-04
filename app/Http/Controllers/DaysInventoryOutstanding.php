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
        $branchId = request('branchId') ?? $branches->keys()->first();
        $chart_time_period = request('chart_time_period') ?? 0;

        $begginingInventory = $this->getBeginningInventory($branchId);
        $endingInventory = $this->getEndingInventory($branchId);

        $cogsAll = ProductInventoryStockManager::where('store_branch_id', $branchId)
            ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));

        $averageInventory = ($begginingInventory + $endingInventory) / 2;

        return Inertia::render('DaysInventoryOutstanding/Index', [
            'filters' => request()->only(['branchId', 'search', 'chart_time_period']),
            'branches' => $branches,
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

    public function getEndingInventory($branch)
    {
        return ProductInventoryStockManager::query()
            ->where('store_branch_id', $branch)
            ->sum('total_cost');
    }

    public function getBeginningInventory($branch)
    {
        return ProductInventoryStockManager::select('product_inventory_id')
            ->where('store_branch_id', $branch)
            ->selectRaw('MIN(id) as first_transaction_id')
            ->where('quantity', '>', 0)
            ->groupBy('product_inventory_id')
            ->get()
            ->map(function ($item) {
                $transaction = ProductInventoryStockManager::find($item->first_transaction_id);
                return [
                    'product_id' => $item->product_inventory_id,
                    'first_quantity' => $transaction->quantity,
                    'transaction_date' => $transaction->transaction_date,
                    'unit_cost' => $transaction->unit_cost,
                    'total_cost' => $transaction->total_cost
                ];
            })
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
