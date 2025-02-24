<?php

namespace App\Http\Controllers;

use App\Models\StoreTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StoreTransactionApprovalController extends Controller
{
    public function index()
    {
        $transactions = StoreTransaction::with('store_transaction_items')
            ->whereNot('is_approved')
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(function ($item) {
                return [
                    'id' => $item->id,
                    'receipt_number' => $item->receipt_number,
                    'order_date' => $item->order_date,
                    'ordered_item_count' => $item->store_transaction_items->count('quantity')
                ];
            });
        return Inertia::render('StoreTransactionApproval/Index', [
            'transactions' => $transactions
        ]);
    }
}
