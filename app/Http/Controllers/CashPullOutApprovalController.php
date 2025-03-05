<?php

namespace App\Http\Controllers;

use App\Models\CashPullOut;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashPullOutApprovalController extends Controller
{
    public function index()
    {
        $cashPullOuts = CashPullOut::with('store_branch')->where('status', 'pending')->latest()->paginate(10);
        return Inertia::render('CashPullOutApproval/Index', [
            'cashPullOuts' => $cashPullOuts
        ]);
    }

    public function show(CashPullOut $cashPullOut)
    {
        $cashPullOut->load(['store_branch', 'cash_pull_out_items.product_inventory.unit_of_measurement']);
        return Inertia::render('CashPullOutApproval/Show', [
            'cashPullOut' => $cashPullOut
        ]);
    }

    public function approve(CashPullOut $cashPullOut)
    {
        $cashPullOut->update(['status' => 'approved']);
        return redirect()->route('cash-pull-out-approval.index');
    }
}
