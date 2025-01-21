<?php

namespace App\Exports;

use App\Models\StoreOrder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Pest\Concerns\Expectable;

class OrderApprovalsExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $search;
    protected $filter;

    public function __construct($search = null, $filter = null)
    {
        $this->search = $search;
        $this->filter = $filter;
    }
    public function query()
    {
        $query = StoreOrder::query()->with(['store_branch', 'supplier']);
        if ($this->search)
            $query->where('order_number', 'like', '%' . $this->search . '%');

        if ($this->filter)
            $query->where('manager_approval_status', $this->filter);

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
            $order->supplier->name,
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
