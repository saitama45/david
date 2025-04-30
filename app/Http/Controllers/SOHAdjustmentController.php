<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SOHAdjustmentController extends Controller
{
    public function index()
    {
        $search = request('search');
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        $items = ProductInventoryStockManager::with('product')
            ->where('store_branch_id', $branchId)
            ->when($search, function ($query) use ($search) {
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('inventory_code', 'like', "%{$search}%");
                });
            })
            ->where('action', 'soh_adjustment')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return Inertia::render('SOHAdjustment/Index', [
            'branches' => $branches,
            'search' => $search,
            'items' => $items,
            'filters' => request()->only(['search', 'branchId']),
        ]);
    }

    public function approveSelectedItems(Request $request)
    {
        $validated = $request->validate([
            'selectedItems' => ['required', 'array'],
            'branchId' => ['required', 'exists:store_branches,id'],
        ]);

        ProductInventoryStockManager::whereIn('id', $validated)
            ->where('store_branch_id', $validated['branchId'])
            ->update();
    }
}
