<?php

namespace App\Exports;

use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StoreTransactionExport implements FromQuery, WithHeadings, WithMapping
{
    protected $from;
    protected $to;
    protected $branchId;
    protected $search;
    public function __construct($search = null, $branchId = null, $from = null, $to = null)
    {
        $this->search = $search;
        $this->branchId = $branchId;
        $this->from = $from;
        $this->to = $to;
    }
    public function query()
    {
        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch']);

        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($this->from && $this->to) {
            $query->whereBetween('order_date', [$this->from, $this->to]);
        }

        if ($this->branchId)
            $query->where('store_branch_id', $this->branchId);

        if ($this->search)
            $query->where('receipt_number', 'like', "%$this->search%");

        return $query;
    }

    public function headings(): array
    {
        return [
            'Store Branch',
            'Receipt Number',
            'TM#',
            'Posted',
            'Date',
            'Discount',
            'Line Total',
            'Net Total'
        ];
    }

    public function map($row): array
    {
        return [
            $row->store_branch->location_code,
            $row->receipt_number,
            $row->tim_number,
            $row->posted,
            $row->order_date,
            $row->store_transaction_items->sum('discount'),
            $row->store_transaction_items->sum('line_total'),
            $row->store_transaction_items->sum('net_total'),
        ];
    }
}
