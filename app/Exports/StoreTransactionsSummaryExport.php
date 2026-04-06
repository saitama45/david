<?php

namespace App\Exports;

use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StoreTransactionsSummaryExport implements FromCollection, WithHeadings, WithMapping
{
    protected $to;
    protected $from;
    protected $branchId;

    public function __construct($to = null, $from = null, $branchId = null)
    {
        $this->to = $to;
        $this->from = $from;
        $this->branchId = $branchId;
    }
    public function collection()
    {
        $from = $this->from ? Carbon::parse($this->from)->format('Y-m-d') : '1999-01-01';
        $to = $this->to ? Carbon::parse($this->to)->format('Y-m-d') : Carbon::today()->addMonth();

        $branchId = $this->branchId;

        return StoreTransaction::query()
            ->leftJoin('store_transaction_items', 'store_transactions.id', '=', 'store_transaction_items.store_transaction_id')
            ->whereBetween('order_date', [$from, $to])
            ->select(
                'store_transactions.order_date',
                DB::raw('COUNT(DISTINCT store_transactions.id) as transaction_count'),
                DB::raw('SUM(store_transaction_items.net_total) as net_total')
            )
            ->when($branchId && $branchId !== 'all', function ($query) use ($branchId) {
                $query->where('store_transactions.store_branch_id', $branchId);
            })
            ->groupBy('store_transactions.order_date')
            ->orderBy('store_transactions.order_date', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'order_date' => $transaction->order_date,
                    'transaction_count' => $transaction->transaction_count,
                    'net_total' => number_format($transaction->net_total ?? 0, 2, '.', '')
                ];
            });
    }

    public function map($row): array
    {
        return [
            $row['order_date'],
            $row['transaction_count'],
            $row['net_total']
        ];
    }

    public function headings(): array
    {
        return [
            'ORDER DATE',
            'TRANSACTIONS COUNT',
            'OVERALL NET TOTAL',
        ];
    }
}
