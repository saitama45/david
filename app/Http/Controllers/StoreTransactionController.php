<?php

namespace App\Http\Controllers;

use App\Exports\StoreTransactionExport;
use App\Http\Requests\StoreTransaction\StoreStoreTransactionRequest;
use App\Http\Requests\StoreTransaction\UpdateStoreTransactionRequest;
use App\Http\Services\StoreTransactionService;
use App\Imports\StoreTransactionImport;
use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;


class StoreTransactionController extends Controller
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

        $query = StoreTransaction::with('store_transaction_items');

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if (DB::connection()->getDriverName() === 'sqlsrv') {
          
            $transactions = $query
                ->select('store_transactions.order_date')
                ->selectRaw('COUNT(*) as transaction_count')
                ->selectRaw('ISNULL(items_total.total_amount, 0) as net_total')
                ->crossApply(DB::raw('
                (SELECT SUM(CAST(sti.net_total AS DECIMAL(10,2))) as total_amount
                FROM store_transaction_items sti
                WHERE sti.store_transaction_id = store_transactions.id) as items_total
            '))
                ->groupBy('store_transactions.order_date', 'items_total.total_amount')
                ->orderBy('store_transactions.order_date', 'desc')
                ->paginate(10)
                ->through(function ($transaction) {
                    return [
                        'order_date' => $transaction->order_date,
                        'transaction_count' => $transaction->transaction_count,
                        'net_total' => str_pad($transaction->net_total ?? 0, 2, '0', STR_PAD_RIGHT)
                    ];
                });
        } else {

            $transactions = $query
                ->select('order_date')
                ->selectRaw('COUNT(*) as transaction_count')
                ->selectRaw('COALESCE((
                SELECT SUM(net_total) 
                FROM store_transaction_items 
                WHERE store_transaction_items.store_transaction_id = store_transactions.id
            ), 0) as net_total')
                ->groupBy('order_date')
                ->orderBy('order_date', 'desc')
                ->paginate(10)
                ->through(function ($transaction) {
                    return [
                        'order_date' => $transaction->order_date,
                        'transaction_count' => $transaction->transaction_count,
                        'net_total' => str_pad($transaction->net_total ?? 0, 2, '0', STR_PAD_RIGHT)
                    ];
                });
        }

        $branches = StoreBranch::options();
        return Inertia::render('StoreTransaction/MainIndex', [
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'transactions' => $transactions
        ]);
    }
    public function index($order_date)
    {
        $transactions = $this->storeTransactionService->getStoreTransactionsList($order_date);
        $branches = StoreBranch::options();

        return Inertia::render('StoreTransaction/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'order_date' => $order_date
        ]);
    }

    public function export()
    {

        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';

        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : Carbon::today()->addMonth();
        $branchId = request('branchId');
        $search = request('search');

        return Excel::download(
            new StoreTransactionExport($search, $branchId, $from, $to),
            'store-transactions-' . now()->format('Y-m-d') . '.xlsx'
        );
    }


    public function import(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(1000900000000000000);
        $request->validate([
            'store_transactions_file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
            ]
        ]);
        try {
            Excel::import(new StoreTransactionImport, $request->file('store_transactions_file'));
            return to_route('store-transactions.index');
        } catch (Exception $e) {
            dd($e);
        }
    }

    public function create()
    {
        $menus = Menu::options();
        $branches = StoreBranch::options();
        return Inertia::render('StoreTransaction/Create', [
            'menus' => $menus,
            'branches' => $branches
        ]);
    }

    public function update(UpdateStoreTransactionRequest $request, StoreTransaction $storeTransaction)
    {
        $this->storeTransactionService->updateStoreTransaction($storeTransaction, $request->validated());
        return to_route('store-transactions.index');
    }

    public function store(StoreStoreTransactionRequest $request)
    {
        $this->storeTransactionService->createStoreTransaction($request->validated());
        return to_route('store-transactions.index');
    }

    public function edit(StoreTransaction $storeTransaction)
    {
        $menus = Menu::options();
        $branches = StoreBranch::options();
        $transaction =  $storeTransaction->load(['store_transaction_items.menu']);

        return Inertia::render('StoreTransaction/Edit', [
            'transaction' => $transaction,
            'menus' => $menus,
            'branches' => $branches
        ]);
    }

    public function show(StoreTransaction $storeTransaction)
    {
        $transaction = $storeTransaction->load(['store_transaction_items.menu', 'store_branch']);
        $transaction = $this->storeTransactionService->getTransactionDetails($transaction);

        return Inertia::render('StoreTransaction/Show', [
            'transaction' => $transaction
        ]);
    }
}
