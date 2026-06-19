<?php

namespace App\Exports;

use App\Enum\OrderRequestStatus; // Kept if still used elsewhere, but not directly for OrderStatus filtering here
use App\Enum\OrderStatus; // Import OrderStatus enum
use App\Http\Services\OrderReceivingService;
use App\Models\StoreOrder;
use App\Models\User;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles; // Import WithStyles
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet; // Import Worksheet
use PhpOffice\PhpSpreadsheet\Style\Fill; // Import Fill
use Carbon\Carbon; // Import Carbon

class ApprovedOrdersExport implements FromQuery, WithHeadings, WithMapping, WithStyles // Added WithStyles interface
{
    use Exportable;

    protected $filters;
    protected $currentFilter; // Receiving status tab filter
    protected $service;

    public function __construct($filters = [], $currentFilter = 'all', ?OrderReceivingService $service = null)
    {
        $this->filters = is_array($filters) ? $filters : ['search' => $filters];
        $this->currentFilter = $currentFilter;
        $this->service = $service ?? app(OrderReceivingService::class);
    }

    public function query()
    {
        $query = StoreOrder::query()->with(['store_branch', 'supplier', 'encoder', 'approver', 'commiter']);

        // Reuse the exact same filter logic as the listing so the export always matches.
        $this->service->applyCommonFilters($query, $this->filters);
        $this->service->applyStatusFilter($query, $this->currentFilter);

        return $query->latest();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Order Number',
            'Supplier',
            'Store Branch',
            'Order Date',
            'Order Placed Date',
            'Receiving Status', // Changed from 'Order Status' for clarity in this context
            'Encoder',
            'Approver',
            'Commiter',
            'Approval Action Date',
            'Commited Action Date',
            'Remarks',
            'Variant',
        ];
    }

    public function map($order): array
    {
        return [
            $order->id,
            $order->order_number,
            $order->supplier->name ?? 'N/A',
            $order->store_branch->name ?? 'N/A',
            Carbon::parse($order->order_date)->format('Y-m-d'),
            Carbon::parse($order->created_at)->format('Y-m-d H:i:s'),
            $order->order_status, // This will be the receiving status
            $order->encoder->full_name ?? 'N/A', // Assuming full_name accessor exists on User model
            $order->approver->full_name ?? 'N/A', // Assuming full_name accessor exists on User model
            $order->commiter->full_name ?? 'N/A', // Assuming full_name accessor exists on User model
            $order->approval_action_date ? Carbon::parse($order->approval_action_date)->format('Y-m-d H:i:s') : 'N/A',
            $order->commited_action_date ? Carbon::parse($order->commited_action_date)->format('Y-m-d H:i:s') : 'N/A',
            $order->remarks,
            $order->variant,
        ];
    }

    /**
     * Apply styles to the worksheet.
     *
     * @param Worksheet $sheet
     */
    public function styles(Worksheet $sheet)
    {
        // Apply style to the first row (headers)
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '00008B', // Dark Blue
                ],
            ],
            'font' => [
                'bold' => true,
                'color' => [
                    'rgb' => 'FFFFFF', // White text
                ],
            ],
        ]);
    }
}
