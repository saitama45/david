<?php

namespace App\Http\Controllers;

use App\Imports\StoreTransactionImport;
use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
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
    public function index()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $search = request('search');
        $branchId = request('branchId');

        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch']);

        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($branchId)
            $query->where('store_branch_id', $branchId);

        $branches = StoreBranch::options();
        if ($search)
            $query->where('receipt_number', 'like', "%$search%");

        $transactions = $query->latest()->paginate(10);

        return Inertia::render('StoreTransaction/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches
        ]);
    }

    public function import(Request $request)
    {
        ini_set('memory_limit', '512M');
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

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'order_date' => ['required'],
            'lot_serial' => ['nullable'],
            'posted' => ['required'],
            'tim_number' => ['required'],
            'receipt_number' => ['required'],
            'store_branch_id' => ['required'],
            'customer_id' => ['nullable'],
            'customer' => ['nullable'],
            'items' => ['required', 'array'],
        ]);
        DB::beginTransaction();
        $transaction = StoreTransaction::findOrFail($id);
        $transaction->update(Arr::except($validated, ['items']));
        $transaction->store_transaction_items()->delete();
        foreach ($validated['items'] as $item) {
            $transaction->store_transaction_items()->create([
                'product_id' => $item['product_id'],
                'base_quantity' => $item['quantity'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'discount' => $item['discount'],
                'line_total' => $item['line_total'],
                'net_total' => $item['net_total'],
            ]);
        }

        DB::commit();

        return to_route('store-transactions.index');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_date' => ['required'],
            'lot_serial' => ['nullable'],
            'posted' => ['required'],
            'tim_number' => ['required'],
            'receipt_number' => ['required'],
            'store_branch_id' => ['required'],
            'customer_id' => ['nullable'],
            'customer' => ['nullable'],
            'items' => ['required', 'array'],
        ]);
        $validated['order_date'] = Carbon::parse($validated['order_date'])->addDay();
        DB::beginTransaction();
        $transaction = StoreTransaction::create(Arr::except($validated, ['items']));

        foreach ($validated['items'] as $item) {
            $transaction->store_transaction_items()->create([
                'product_id' => $item['product_id'],
                'base_quantity' => $item['quantity'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'discount' => $item['discount'],
                'line_total' => $item['line_total'],
                'net_total' => $item['net_total'],
            ]);
        }
        DB::commit();

        return to_route('store-transactions.index');
    }

    public function edit($id)
    {
        $menus = Menu::options();
        $branches = StoreBranch::options();
        $transaction = StoreTransaction::with(['store_transaction_items.menu'])->findOrFail($id);
        return Inertia::render('StoreTransaction/Edit', [
            'transaction' => $transaction,
            'menus' => $menus,
            'branches' => $branches
        ]);
    }

    public function show($id)
    {
        $transaction = StoreTransaction::with(
            ['store_transaction_items.menu', 'store_branch']
        )
            ->findOrFail($id);

        $items = $transaction->store_transaction_items->map(function ($item) {
            return [
                'product_id' => $item->menu->product_id,
                'name' => $item->menu->name,
                'base_quantity' => $item->base_quantity,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount,
                'line_total' => $item->line_total,
                'net_total' => $item->net_total,
            ];
        });
        $transaction = [
            'branch' => $transaction->store_branch->name,
            'lot_serial' => $transaction->lot_serial ?? 'N/a',
            'date' => $transaction->order_date,
            'posted' => $transaction->posted,
            'tim_number' => $transaction->tim_number,
            'receipt_number' => $transaction->receipt_number,
            'customer_id' => $transaction->customer_id ?? 'N/a',
            'customer' => $transaction->customer ?? 'N/a',
            'cancel_reason' => $transaction->cancel_reason ?? 'N/a',
            'reference_number' => $transaction->reference_number ?? 'N/a',
            'remarks' => $transaction->remarks ?? 'N/a',
            'items' => $items
        ];


        return Inertia::render('StoreTransaction/Show', [
            'transaction' => $transaction
        ]);
    }
}
