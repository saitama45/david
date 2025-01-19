<?php

namespace App\Http\Controllers;

use App\Imports\StoreTransactionImport;
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
            $query->where('name', 'like', "%$search%");

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
            return back()->with('success', 'Transactions imported successfully.');
        } catch (Exception $e) {
            dd($e);
        }
    }

    public function create()
    {
        return Inertia::render('StoreTransaction/Create');
    }
}
