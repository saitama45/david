<?php

namespace App\Http\Controllers;

use App\Imports\StoreTransactionImport;
use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class StoreTransactionController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch']);

        if ($search)
            $query->where('receipt_number', 'like', "%$search%");

        $transactions = $query->latest()->paginate(10);

        return Inertia::render('StoreTransaction/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['search'])
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
