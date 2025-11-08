<?php

namespace App\Exports;

use App\Enum\OrderRequestStatus; // Kept if still used elsewhere, but not directly for OrderStatus filtering here
use App\Enum\OrderStatus; // Import OrderStatus enum
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

    protected $search;
    protected $currentFilter; // New property for status filter

    public function __construct($search = null, $currentFilter = 'all') // Constructor now accepts filter
    {
        $this->search = $search;
        $this->currentFilter = $currentFilter;
    }

    public function query()
    {
        $query = StoreOrder::query()->with(['store_branch', 'supplier', 'encoder', 'approver', 'commiter']);

        // Apply branch filtering based on the logged-in user's assignments
        $user = User::rolesAndAssignedBranches(); // Assuming this returns user info and assigned branches
        if (!$user['isAdmin']) {
            $query->whereIn('store_branch_id', $user['assignedBranches']);
        }

        // Apply status filter based on $this->currentFilter
        if ($this->currentFilter === 'all') {
            // "All" for receiving means orders that are commited, received, or incomplete
            $query->whereIn('order_status', [
                OrderStatus::COMMITTED->value,
                OrderStatus::RECEIVED->value,
                OrderStatus::INCOMPLETE->value
            ]);
        } else {
            // Determine the canonical lowercase status value from the enum
            $statusToFilter = '';
            switch ($this->currentFilter) {
                case 'commited':
                    $statusToFilter = strtolower(OrderStatus::COMMITTED->value);
                    break;
                case 'received':
                    $statusToFilter = strtolower(OrderStatus::RECEIVED->value);
                    break;
                case 'incomplete':
                    $statusToFilter = strtolower(OrderStatus::INCOMPLETE->value);
                    break;
                // Add other cases if you introduce more specific tabs
            }

            if ($statusToFilter) {
                // Apply specific status filter using a case-insensitive comparison with canonical enum value
                $query->whereRaw('LOWER(order_status) = ?', [$statusToFilter]);
            } else {
                // If an unknown filter is passed, return an empty query
                return $query->whereRaw('1=0');
            }
        }

        // Apply search logic
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('order_number', 'like', '%' . $this->search . '%')
                  ->orWhereHas('supplier', function ($sq) {
                      $sq->where('name', 'like', '%' . $this->search . '%');
                  })
                  ->orWhereHas('store_branch', function ($bq) {
                      $bq->where('name', 'like', '%' . $this->search . '%');
                  });
            });
        }

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
