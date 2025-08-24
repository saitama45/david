<?php

namespace App\Http\Controllers;

use App\Exports\StoreTransactionExport;
use App\Exports\StoreTransactionsSummaryExport;
use App\Http\Requests\StoreTransaction\StoreStoreTransactionRequest;
use App\Http\Requests\StoreTransaction\UpdateStoreTransactionRequest;
use App\Http\Services\StoreTransactionService;
use App\Imports\StoreTransactionImport;
use App\Jobs\StartImportJob;
use App\Models\POSMasterfile;
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
use Illuminate\Support\Facades\Log; // Ensure Log facade is imported

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

        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? (optional($branches->first())['value'] ?? null);

        $transactions = StoreTransaction::query()
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
                    'net_total' => number_format($transaction->net_total ?? 0, 2, '.', ''),
                ];
            });


        $branches = StoreBranch::options();

        return Inertia::render('StoreTransaction/MainIndex', [
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'transactions' => $transactions
        ]);
    }
    public function index()
    {
        $transactions = $this->storeTransactionService->getStoreTransactionsList();
        $branches = StoreBranch::options();

        return Inertia::render('StoreTransaction/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'order_date' => request('order_date')
        ]);
    }

    public function exportMainIndex()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : Carbon::today()->addMonth()->format('Y-m-d');
        $branchId = request('branchId');


        return Excel::download(
            new StoreTransactionsSummaryExport($to, $from, $branchId),
            'store-transactions-summary-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function export()
    {

        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : null;

        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : null;
        $branchId = request('branchId');
        $search = request('search');

        return Excel::download(
            new StoreTransactionExport($search, $branchId, $from, $to, request('order_date')),
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

        DB::beginTransaction();
        try {
            $import = new StoreTransactionImport;
            Excel::import($import, $request->file('store_transactions_file'));
            DB::commit();

            $skippedRows = $import->getSkippedRows();

            if (!empty($skippedRows)) {
                session()->flash('skipped_import_rows', $skippedRows);
                session()->flash('warning', 'Store transactions imported with some skipped rows. Please check the details below.');
                // --- DEBUG LOG START ---
                Log::debug('Flash messages set for skipped rows:', [
                    'skipped_import_rows' => session('skipped_import_rows'),
                    'warning' => session('warning')
                ]);
                // --- DEBUG LOG END ---
                return back();
            }

            session()->flash('success', 'Store transactions imported successfully.');
            // --- DEBUG LOG START ---
            Log::debug('Flash message set for success:', [
                'success' => session('success')
            ]);
            // --- DEBUG LOG END ---
            return back();

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("StoreTransaction Import Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            session()->flash('error', 'Import failed: ' . $e->getMessage());
            // --- DEBUG LOG START ---
            Log::debug('Flash message set for error:', [
                'error' => session('error')
            ]);
            // --- DEBUG LOG END ---
            return back()->withInput();
        }
    }


    public function create()
    {
        $posMasterfiles = POSMasterfile::options();
        $branches = StoreBranch::options();
        return Inertia::render('StoreTransaction/Create', [
            'posMasterfiles' => $posMasterfiles,
            'branches' => $branches
        ]);
    }

    public function update(UpdateStoreTransactionRequest $request, StoreTransaction $storeTransaction)
    {
        $this->storeTransactionService->updateStoreTransaction($storeTransaction, $request->validated());
        return to_route('store-transactions.index')->with('success', 'Store transaction updated successfully.');
    }

    public function store(StoreStoreTransactionRequest $request)
    {
        $this->storeTransactionService->createStoreTransaction($request->validated());
        return to_route('store-transactions.index')->with('success', 'Store transaction created successfully.');
    }

    public function edit(StoreTransaction $storeTransaction)
    {
        $posMasterfiles = POSMasterfile::options();
        $branches = StoreBranch::options();
        $transaction =  $storeTransaction->load(['store_transaction_items.posMasterfile']);

        return Inertia::render('StoreTransaction/Edit', [
            'transaction' => $transaction,
            'posMasterfiles' => $posMasterfiles,
            'branches' => $branches
        ]);
    }

    public function show(StoreTransaction $storeTransaction)
    {
        $transaction = $storeTransaction->load(['store_transaction_items.posMasterfile', 'store_branch']);
        $transaction = $this->storeTransactionService->getTransactionDetails($transaction);

        return Inertia::render('StoreTransaction/Show', [
            'transaction' => $transaction
        ]);
    }
}
