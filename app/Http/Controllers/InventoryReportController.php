<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryReportController extends Controller
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

        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? 0;

        $query = ProductInventoryStockManager::query();

        if ($time_period != 0) {
            $query->whereMonth('transaction_date', '<=', $time_period);
        } else {
            $query->whereYear('transaction_date', Carbon::today()->year);
        }

        $query->join('sap_masterfiles as sap', 'product_inventory_stock_managers.product_inventory_id', '=', 'sap.id')
            ->select(
                'sap.ItemCode',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(total_cost) as total_cost')
            )
            ->whereIn('store_branch_id', $branchIds)
            ->groupBy('sap.ItemCode');

        $inventories = $query->paginate(10);

        $summarizedInventories = $inventories->through(function ($item) {
            // Pick the SAP record where AltUOM == BaseUOM for display
            $sapRecord = \App\Models\SAPMasterfile::where('ItemCode', $item->ItemCode)
                ->whereColumn('AltUOM', 'BaseUOM')
                ->first();

            return [
                'quantity' => $item->total_quantity,
                'total_cost' => number_format($item->total_cost, 2, '.', ','),
                'item' => $sapRecord ? $sapRecord->ItemDescription : 'Unknown',
                'inventory_code' => $item->ItemCode
            ];
        });

        return Inertia::render('InventoryReport/Index', [
            'inventories' => $summarizedInventories,
            'branches' => $branchOptions,
            'filters' => request()->only(['time_period', 'branchId', 'search']),
            'timePeriods' => $timePeriods
        ]);
    }
}
