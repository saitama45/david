<?php

namespace App\Exports;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use App\Models\User;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

use function Laravel\Prompts\search;

class ApprovedOrdersExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $search;

    public function __construct($search = null)
    {
        $this->search = $search;
    }

    public function query()
    {
        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->where('order_request_status', OrderRequestStatus::APRROVED->value);


        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($this->search)
            $query->where('order_number', 'like', '%' . $this->search . '%');

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Supplier',
            'Store Branch',
            'Order Number',
            'Order Date',
            'Order Place Date',
            'Receiving Status',
        ];
    }

    public function map($order): array
    {
        return [
            $order->supplier->name,
            $order->store_branch->name,
            $order->order_number,
            $order->order_date,
            $order->created_at,
            $order->order_status,
        ];
    }
}
