<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesReportController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();
        $search = request('search');
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? $timePeriods[1];

        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch'])
            ->where('store_branch_id', $branchId);

        if ($time_period) {
            if ($time_period != 0) {
                $query->whereMonth('order_date', $time_period);
            } else {
                $query->whereYear('order_date', Carbon::today()->year);
            }
        }

        if ($search)
            $query->where('receipt_number', 'like', "%$search%");

        $transactions =  $query->latest()->paginate(10)->withQueryString()->through(function ($item) {
            return [
                'id' => $item->id,
                'store_branch' => $item->store_branch->name,
                'receipt_number' => $item->receipt_number,
                'item_count' => $item->store_transaction_items->count(),
                'net_total' => str_pad($item->store_transaction_items->sum('net_total'), 2),
                'order_date' => $item->order_date
            ];
        });

        return Inertia::render('SalesReport/Index', [
            'transactions' => $transactions,
            'branches' => $branches,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'timePeriods' => $timePeriods
        ]);
    }
}
