<?php

namespace App\Http\Controllers;

use App\Models\StoreTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StoreTransactionController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreTransaction::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $transactions = $query->paginate(10);
        return Inertia::render('StoreTransaction/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['search'])
        ]);
    }

    public function create()
    {
        return Inertia::render('StoreTransaction/Create');
    }
}
