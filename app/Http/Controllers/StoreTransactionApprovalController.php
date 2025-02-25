<?php

namespace App\Http\Controllers;

use App\Http\Services\StoreTransactionService;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StoreTransactionApprovalController extends Controller
{
    protected $storeTransactionService;

    public function __construct(StoreTransactionService $storeTransactionService)
    {
        $this->storeTransactionService = $storeTransactionService;
    }

    public function mainIndex()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : Carbon::today()->addMonth();

        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? $branches->keys()->first();

        $transactions = StoreTransaction::query()
            ->whereNot('is_approved')
            ->leftJoin('store_transaction_items', 'store_transactions.id', '=', 'store_transaction_items.store_transaction_id')
            ->whereBetween('order_date', [$from, $to])
            ->select(
                'store_transactions.order_date',
                DB::raw('COUNT(DISTINCT store_transactions.id) as transaction_count'),
                DB::raw('SUM(store_transaction_items.net_total) as net_total')
            )
            ->where('store_transactions.store_branch_id', $branchId)
            ->groupBy('store_transactions.order_date')
            ->orderBy('store_transactions.order_date', 'desc')
            ->paginate(10)
            ->through(function ($transaction) {
                return [
                    'order_date' => $transaction->order_date,
                    'transaction_count' => $transaction->transaction_count,
                    'net_total' => str_pad($transaction->net_total ?? 0, 2, '0', STR_PAD_RIGHT)
                ];
            });

        $branches = StoreBranch::options();

        return Inertia::render('StoreTransactionApproval/MainIndex', [
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'transactions' => $transactions
        ]);
    }
    public function index()
    {
        $transactions = $this->storeTransactionService->getStoreTransactionsForApprovalList();
        $branches = StoreBranch::options();

        $branches = StoreBranch::options();
        return Inertia::render('StoreTransactionApproval/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'order_date' => request('order_date')
        ]);
    }

    public function show(StoreTransaction $storeTransaction)
    {
        $transaction = $storeTransaction->load(['store_transaction_items.menu', 'store_branch']);
        $transaction = $this->storeTransactionService->getTransactionDetails($transaction);

        return Inertia::render('StoreTransactionApproval/Show', [
            'transaction' => $transaction
        ]);
    }
}
