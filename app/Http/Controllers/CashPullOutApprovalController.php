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
}
