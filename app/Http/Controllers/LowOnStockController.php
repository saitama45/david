<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LowOnStockController extends Controller
{
    public function index()
    {
        $search = request('search');
        $branches = StoreBranch::options();
        $branch = request('branchId') ?? $branches->keys()->first();
        $query = ProductInventoryStock::with('product');

        if ($search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
            });
        }

        $items = $query
            ->where('store_branch_id', $branch)
            ->select('*', DB::raw('(quantity - used) as available_stock'))
            ->whereRaw('(quantity - used) <= 20')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('LowOnStock/Index', [
            'filters' => request()->only(['branch', 'search']),
            'items' => $items,
            'branches' => $branches
        ]);
    }
}
