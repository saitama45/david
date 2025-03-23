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
        $branchId = request('branchId') ?? $branches->keys()->first();
        $search = request('search');

        $query = ProductInventoryStock::with('product')
            ->where('store_branch_id', $branchId)
            ->select('*', DB::raw('(quantity - used) as stock_on_hand'))
            ->orderBy('stock_on_hand', 'desc')
            ->take(10);

        if ($search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
            });
        }


        $items = $query->get()
            ->map(function ($item) {
                return [
                    'name' => $item->product->name,
                    'inventory_code' => $item->product->inventory_code,
                    'total_cost' => $this->number_format($item->stock_on_hand * $item->product->cost),
                    'current_cost' => $this->number_format($item->product->cost),
                    'quantity' => $this->number_format($item->stock_on_hand)
                ];
            });
        return Inertia::render('Top10Inventories/Index', [
            'items' => $items,
            'branches' => $branches,
            'filters' => request()->only(['branchId', 'search']),
        ]);
    }

    public function number_format($number)
    {
        return number_format($number, 2, '.', ',');
    }
}
