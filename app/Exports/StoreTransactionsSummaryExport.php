<?php

namespace App\Exports;

use App\Models\StoreTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StoreTransactionsSummaryExport implements FromQuery, WithHeadings, WithMapping
{
    protected $to;
    protected $from;

    public function __construct($to = null, $from = null)
    {
        $this->to = $to;
        $this->from = $from;
    }
    public function query()
    {
        $from = $this->from ? Carbon::parse($this->from)->format('Y-m-d') : '1999-01-01';
        $to = $this->to ? Carbon::parse($this->to)->format('Y-m-d') : Carbon::today()->addMonth();

        return StoreTransaction::query()
            ->leftJoin('store_transaction_items', 'store_transactions.id', '=', 'store_transaction_items.store_transaction_id')
            ->whereBetween('order_date', [$from, $to])
            ->select(
                'store_transactions.order_date',
                DB::raw('COUNT(DISTINCT store_transactions.id) as transaction_count'),
                DB::raw('SUM(store_transaction_items.net_total) as net_total')
            )
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
    }
}
