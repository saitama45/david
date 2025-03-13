<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashPullOut\StoreCashPullOutRequest;
use App\Models\CashPullOut;
use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DirectReceivingController extends Controller
{
    public function index()
    {
        $cashPullOuts = CashPullOut::with('store_branch')->latest()->paginate(10);
        return Inertia::render('DirectReceiving/Index', [
            'directReceivings' => $cashPullOuts
        ]);
    }

    public function create()
    {
        $branches = StoreBranch::options();
        $products = ProductInventory::options();
        return Inertia::render('DirectReceiving/Create', [
            'products' => $products,
            'branches' => $branches
        ]);
    }

    public function store(StoreCashPullOutRequest $request)
    {
        $validated = $request->validated();

        DB::beginTransaction();
        $cashPullOut = CashPullOut::create(Arr::except($validated, 'orders'));

        foreach ($validated['orders'] as $order) {
            $cashPullOut->cash_pull_out_items()->create([
                'product_inventory_id' => $order['id'],
                'quantity_ordered' => $order['quantity'],
                'is_approved' => true
            ]);

            ProductInventoryStock::where('product_inventory_id', $order['id'])
                ->where('store_branch_id', $cashPullOut->store_branch_id)
                ->increment('quantity', $order['quantity']);

            ProductInventoryStockManager::create([
                'product_inventory_id' => $order['id'],
                'store_branch_id' => $cashPullOut->store_branch_id,
                'cost_center_id' => null,
                'quantity' => $order['quantity'],
                'action' => 'add quantity',
                'remarks' => "Added quantity from direct receiving (ID No. $cashPullOut->id)"
            ]);
        }
        DB::commit();
        return redirect()->route('direct-receiving.index');
    }
}
