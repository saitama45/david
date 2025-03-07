<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use Illuminate\Http\Request;
use Inertia\Inertia; 

class AccountPayableController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        $storeOrderItems = StoreOrderItem::with(['store_order', 'product_inventory'])
            ->where('quantity_received', '>', 0)
            ->whereHas('store_order', function ($query) use ($branchId) {
                $query->where('store_branch_id', 16);
                $query->whereIn('order_status', ['received', 'incomplete']);
            })
            ->latest()
            ->paginate(10);

        return Inertia::render('AccountPayable/Index', [
            'storeOrderItems' => $storeOrderItems
        ]);
    }
}
