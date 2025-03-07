<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UpcomingInventoryController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        $inventories = StoreOrderItem::with(['store_order', 'product_inventory'])
            ->whereHas('store_order', function ($query) use ($branchId) {
                $query->where('store_branch_id', 16);
                $query->where('order_status', 'commited');
            })
            ->latest()
            ->paginate(10);



        return Inertia::render('UpcomingInventories/Index', [
            'inventories' => $inventories
        ]);
    }
}
