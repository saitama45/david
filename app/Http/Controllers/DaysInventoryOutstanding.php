<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DaysInventoryOutstanding extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $search = request('search');
        $query = ProductInventoryStockManager::query()->with('product')
            ->where('store_branch_id', $branchId);

        if ($search) {
            $query->whereHas('product', function ($query) use ($search) {
                $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
            });
        }

        $items = $query->paginate(10)->withQueryString();

        return Inertia::render('DaysInventoryOutstanding/Index', [
            'items' => $items,
            'branches' => $branches,
            'filters' => request()->only(['branchId', 'search']),
        ]);
    }
}
