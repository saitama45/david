<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashPullOut\StoreCashPullOutRequest;
use App\Models\CashPullOut;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CashPullOutController extends Controller
{
    public function index()
    {
        $cashPullOuts = CashPullOut::with('store_branch')->latest()->paginate(10);

        return Inertia::render('CashPullOut/Index', [
            'cashPullOuts' => $cashPullOuts
        ]);
    }

    public function create()
    {
        $branches = StoreBranch::options();
        $products = ProductInventory::options();
        return Inertia::render('CashPullOut/Create', [
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
            ]);
        }
        DB::commit();
        return redirect()->route('cash-pull-out.index');
    }

    public function show(CashPullOut $cashPullOut)
    {
        $cashPullOut->load(['store_branch', 'cash_pull_out_items.product_inventory.unit_of_measurement']);
        return Inertia::render('CashPullOut/Show', [
            'cashPullOut' => $cashPullOut
        ]);
    }
}
