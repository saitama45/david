<?php

namespace App\Http\Services;

use App\Models\StoreTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Ensure Log facade is imported

class StoreTransactionService
{

    public function createStoreTransaction(array $data)
    {
        $data['order_date'] = Carbon::parse($data['order_date'])->addDay();
        DB::beginTransaction();
        $transaction = StoreTransaction::create(Arr::except($data, ['items']));

        foreach ($data['items'] as $item) {
            $transaction->store_transaction_items()->create([
                'product_id' => $item['product_id'], // This is now POSMasterfile.id
                'base_quantity' => $item['quantity'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'discount' => $item['discount'],
                'line_total' => $item['line_total'],
                'net_total' => $item['net_total'],
            ]);
        }
        DB::commit();
    }

    public function getTransactionDetails(StoreTransaction $transaction)
    {
        $transaction->load('store_transaction_items.posMasterfile');

        $items = $transaction->store_transaction_items->map(function ($item) {
            return [
                'product_id' => $item->posMasterfile->id,
                'pos_code' => $item->posMasterfile->POSCode,
                'name' => $item->posMasterfile->POSDescription,
                'base_quantity' => $item->base_quantity,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount,
                'line_total' => $item->line_total,
                'net_total' => $item->net_total,
            ];
        });
        return [
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
            'remarks' => $transaction->remarks ?? 'N/a', // Corrected: Using actual remarks if available
            'items' => $items,
        ];
    }

    public function getStoreTransactionsList()
    {
        $from = request('from');
        $to = request('to');
        $search = request('search');
        $branchId = request('branchId');
        $order_date = request('order_date');

        $query = StoreTransaction::query()->with(['store_transaction_items.posMasterfile', 'store_branch']);

        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($branchId !== 'all') {
            $query->where('store_branch_id', $branchId);
        }

        // CRITICAL FIX: Use whereRaw with CAST for robust date comparison for both single day and range
        $from_formatted = $from ? Carbon::parse($from)->format('Y-m-d') : null;
        $to_formatted = $to ? Carbon::parse($to)->format('Y-m-d') : null;

        if ($order_date) {
            $query->whereRaw('CAST(order_date as DATE) = ?', [Carbon::parse($order_date)->format('Y-m-d')]);
        } elseif ($from && $to) {
            $query->whereRaw('CAST(order_date as DATE) BETWEEN ? AND ?', [$from_formatted, $to_formatted]);
        }


        if ($search)
            $query->where('receipt_number', 'like', "%$search%");

        // --- DEBUG LOG START ---
        Log::debug('StoreTransactionService: getStoreTransactionsList filters:', [
            'from_request' => request('from'),
            'to_request' => request('to'),
            'search' => $search,
            'branchId' => $branchId,
            'order_date_param' => $order_date,
            'from_formatted_for_query' => $from_formatted,
            'to_formatted_for_query' => $to_formatted,
            'final_query_sql' => $query->toSql(),
            'final_query_bindings' => $query->getBindings(),
        ]);
        $raw_results = $query->get();
        Log::debug('StoreTransactionService: Raw query results count:', ['count' => $raw_results->count()]);
        Log::debug('StoreTransactionService: Raw query results (first 5):', ['data' => $raw_results->take(5)->toArray()]);
        // --- DEBUG LOG END ---


        $result = $query->latest()->paginate(10)->withQueryString()->through(function ($item) {
            return [
                'id' => $item->id,
                'store_branch' => $item->store_branch->name,
                'branch_code' => $item->store_branch->branch_code,
                'receipt_number' => $item->receipt_number,
                'item_count' => $item->store_transaction_items->count(),
                'net_total' => number_format($item->store_transaction_items->sum('net_total'), 2),
                'order_date' => $item->order_date,
            ];
        });

        return $result;
    }

    public function getStoreTransactionsForApprovalList()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : null;
        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : null;
        $search = request('search');
        $branchId = request('branchId'); // This can be 'all' or an actual ID

        $query = StoreTransaction::query()->with(['store_transaction_items.posMasterfile', 'store_branch']);

        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($branchId !== 'all') {
            $query->where('store_branch_id', $branchId);
        }

        if (!$from && !$to && $order_date = request('order_date')) {
            $query->where('order_date', $order_date);
        }

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($search)
            $query->where('receipt_number', 'like', "%$search%");

        $result = $query->latest()->paginate(10)->withQueryString()->through(function ($item) {
            return [
                'id' => $item->id,
                'store_branch' => $item->store_branch->name,
                'receipt_number' => $item->receipt_number,
                'item_count' => $item->store_transaction_items->count(),
                'net_total' => number_format($item->store_transaction_items->sum('net_total'), 2),
                'order_date' => $item->order_date,
            ];
        });

        return $result;
    }

    public function updateStoreTransaction(StoreTransaction $transaction, array $data)
    {
        DB::beginTransaction();
        $transaction->update(Arr::except($data, ['items']));
        $transaction->store_transaction_items()->delete();
        foreach ($data['items'] as $item) {
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
    }
}
