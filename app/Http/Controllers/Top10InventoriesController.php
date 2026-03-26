<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStock;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class Top10InventoriesController extends Controller
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
        $inventory_type = request('inventory_type') ?? 'quantity';

        $query = ProductInventoryStock::with('sapMasterfile')
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile')
            ->select('product_inventory_stocks.*', DB::raw('(quantity - used) as stock_on_hand'));

        if ($inventory_type === 'cost') {
            $query->join('sap_masterfiles', 'product_inventory_stocks.product_inventory_id', '=', 'sap_masterfiles.id')
                ->orderByRaw("(quantity - used) * sap_masterfiles.cost DESC");
        } else {
            $query->orderBy('stock_on_hand', 'desc');
        }

        if ($search) {
            $query->whereHas('sapMasterfile', function ($query) use ($search) {
                $query->whereAny(['ItemDescription', 'ItemCode'], 'like', "%$search%");
            });
        }

        $items = $query->take(10)->get()
            ->map(function ($item) {
                return [
                    'name' => $item->sapMasterfile->ItemDescription,
                    'inventory_code' => $item->sapMasterfile->ItemCode,
                    'total_cost' => $this->number_format($item->stock_on_hand * $item->sapMasterfile->cost),
                    'current_cost' => $this->number_format($item->sapMasterfile->cost),
                    'quantity' => $this->number_format($item->stock_on_hand)
                ];
            });

        return Inertia::render('Top10Inventories/Index', [
            'items' => $items,
            'branches' => $branchOptions,
            'filters' => request()->only(['branchId', 'search', 'inventory_type']),
        ]);
    }

    public function number_format($number)
    {
        return number_format($number, 2, '.', ',');
    }
}
