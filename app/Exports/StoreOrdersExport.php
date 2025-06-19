<?php

namespace App\Exports;

use App\Models\StoreOrder;
use App\Models\User;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StoreOrdersExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $search;
    protected $branchId;
    protected $filterQuery;
    protected $from;
    protected $to;
    public function __construct($search = null, $branchId = null, $filterQuery = null, $from = null, $to = null)
    {
        $this->search = $search;
        $this->branchId = $branchId;
        $this->filterQuery = $filterQuery;
        $this->from = $from;
        $this->to = $to;
    }
    public function query()
    {
        $query = StoreOrder::query()->with(['encoder', 'approver', 'commiter', 'store_branch', 'supplier']);

        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($this->from && $this->to) {
            $query->whereBetween('order_date', [$this->from, $this->to]);
        }

        if ($this->filterQuery !== 'all')
            $query->where('order_request_status', $this->filterQuery);

        if ($this->branchId)
            $query->where('store_branch_id', $this->branchId);

        if ($this->search)
            $query->where('order_number', 'like', '%' . $this->search . '%')
                ->orWhereHas('store_branch', function ($query) {
                    $query->where('name', 'like', '%' . $this->search . '%');
                });

        $query
            ->where('variant', 'regular')
            ->latest();

        return $query;
    }

    public function headings(): array
    {
        return [
            'Encoder',
            'Supplier',
            'Store Branch',
            'Commiter',
            'Approver',
            'Order Number',
            'Order Date',
            'Order Status',
            'Order Request Status',
            'Manager Approval Status',
            'Remarks',
            'Variant',
            'Approval Action Date'
        ];
    }

    public function map($order): array
    {
        return [
            $order->encoder?->full_name ?? 'N/a',
            $order->supplier?->name ?? 'N/a',
            $order->store_branch->name,
            $order->commiter?->full_name ?? 'N/a',
            $order->approver?->full_name ?? 'N/a',
            $order->order_number,
            $order->order_date,
            $order->order_status,
            $order->order_request_status,
            $order->manager_approval_status,
            $order->remarks,
            $order->variant,
            $order->approval_action_date ?? 'N/a'
        ];
    }
}
