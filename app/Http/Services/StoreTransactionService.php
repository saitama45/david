<?php

namespace App\Http\Services;

use App\Models\StoreTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StoreTransactionService
{

    public function createStoreTransaction(array $data)
    {
        $data['order_date'] = Carbon::parse($data['order_date'])->addDay();
        DB::beginTransaction();
        $transaction = StoreTransaction::create(Arr::except($data, ['items']));

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

    public function getTransactionDetails(StoreTransaction $transaction)
    {
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
            'remarks' => $transaction->remarks ?? 'N/a',
            'items' => $items
        ];
    }

    public function getStoreTransactionsList()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : null;
        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : null;
        $search = request('search');
        $branchId = request('branchId');
        $order_date = request('order_date');



        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch'])
            ->where('store_branch_id', $branchId);



        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);


        if (!$from && !$to && $order_date) {
            $query->where('order_date', $order_date);
        }



        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($branchId)
            $query->where('store_branch_id', $branchId);


        if ($search)
            $query->where('receipt_number', 'like', "%$search%");

        $result = $query->latest()->paginate(10)->withQueryString()->through(function ($item) {
            return [
                'id' => $item->id,
                'store_branch' => $item->store_branch->name,
                'receipt_number' => $item->receipt_number,
                'item_count' => $item->store_transaction_items->count(),
                'net_total' => str_pad($item->store_transaction_items->sum('net_total'), 2),
                'order_date' => $item->order_date
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
