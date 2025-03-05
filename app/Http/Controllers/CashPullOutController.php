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
        return Inertia::render('CashPullOut/Index');
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
}
