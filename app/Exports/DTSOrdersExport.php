<?php

namespace App\Exports;

use App\Models\StoreOrder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DTSOrdersExport implements FromCollection, WithHeadings
{
    protected array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    /**
    * @return Collection
    */
    public function collection(): Collection
    {
        $query = StoreOrder::with('store_branch')
            ->where('variant', 'dropshipping');

        // Apply filters
        if (!empty($this->filters['branchId']) && $this->filters['branchId'] !== 'all') {
            $query->where('store_branch_id', $this->filters['branchId']);
        }

        if (!empty($this->filters['search'])) {
            $query->where('order_number', 'like', '%' . $this->filters['search'] . '%');
        }

        if (!empty($this->filters['filterQuery']) && $this->filters['filterQuery'] !== 'all') {
            $query->where('order_status', $this->filters['filterQuery']);
        }

        if (!empty($this->filters['from']) && !empty($this->filters['to'])) {
            $query->whereBetween('order_date', [$this->filters['from'], $this->filters['to']]);
        }

        $orders = $query->get();

        return $orders->map(function ($order) {
            return [
                'ID' => $order->id,
                'Store Branch' => $order->store_branch?->name,
                'Order Number' => $order->order_number,
                'Order Status' => $order->order_status,
                'Delivery Date' => $order->order_date,
                'Created At' => $order->created_at,
            ];
        });
    }

    /**
    * @return array
    */
    public function headings(): array
    {
        return [
            'ID',
            'Store Branch',
            'Order Number',
            'Order Status',
            'Delivery Date',
            'Created At',
        ];
    }
}
