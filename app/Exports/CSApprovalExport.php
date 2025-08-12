<?php

namespace App\Exports;

use App\Models\StoreOrder;
use App\Models\Supplier; // Added missing use statement for Supplier model
use Maatwebsite\Excel\Concerns\Exportable; // Keep Exportable trait as it was in user's provided code
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles; // Added missing use statement for WithStyles
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet; // Added missing use statement for Worksheet
use PhpOffice\PhpSpreadsheet\Style\Fill; // Added missing use statement for Fill
use Carbon\Carbon; // Added missing use statement for Carbon
use App\Enum\OrderStatus; // Import OrderStatus enum

class CSApprovalExport implements FromQuery, WithHeadings, WithMapping, WithStyles // Added WithStyles interface
{
    use Exportable; // Keep Exportable trait
    protected $search;
    protected $currentSupplierFilter; // Renamed from $filter for clarity and consistency
    protected $assignedSupplierCodes; // New property for assigned supplier codes
    protected $currentStatusFilter; // This will now always be 'approved'

    public function __construct($search = null, $currentSupplierFilter = 'all', array $assignedSupplierCodes = [], $currentStatusFilter = 'approved') // Updated constructor
    {
        $this->search = $search;
        $this->currentSupplierFilter = $currentSupplierFilter;
        $this->assignedSupplierCodes = $assignedSupplierCodes;
        $this->currentStatusFilter = $currentStatusFilter; // Initialize new property
    }

    public function query()
    {
        $query = StoreOrder::query()
            ->with(['supplier', 'store_branch', 'encoder', 'approver', 'commiter']); // Ensure all necessary relationships are eager loaded

        // --- Step 1: Get the IDs of the suppliers that match the assigned supplier codes ---
        // This is crucial for filtering StoreOrders by supplier_id
        $assignedSupplierIds = Supplier::whereIn('supplier_code', $this->assignedSupplierCodes)
                                       ->pluck('id')
                                       ->toArray();

        // If no suppliers are assigned or found, return an empty query to prevent errors
        if (empty($assignedSupplierIds)) {
            return $query->whereRaw('1=0'); // Forces an empty result set
        }

        // Always filter orders by the user's assigned supplier IDs
        $query->whereIn('supplier_id', $assignedSupplierIds);

        // Apply specific supplier filter if a tab other than 'all' is selected
        if ($this->currentSupplierFilter !== 'all') {
            // Get the ID for the specifically selected supplier code
            $specificSupplierId = Supplier::where('supplier_code', $this->currentSupplierFilter)->value('id');
            if ($specificSupplierId) {
                $query->where('supplier_id', $specificSupplierId);
            } else {
                // If a specific supplier code is selected but its ID is not found,
                // return an empty result set for orders.
                return $query->whereRaw('1=0'); // Force no results
            }
        }

        // Apply static status filter: ALWAYS filter by 'approved'
        $query->where('order_status', OrderStatus::APPROVED->value);

        // Apply search logic
        if ($this->search) {
            $query->where(function ($query) {
                $query->where('order_number', 'like', '%' . $this->search . '%')
                      ->orWhereHas('supplier', function ($q) {
                          $q->where('name', 'like', '%' . $this->search . '%');
                      })
                      ->orWhereHas('store_branch', function ($q) {
                          $q->where('name', 'like', '%' . $this->search . '%');
                      });
            });
        }

        return $query->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        // Using the headings provided in your latest code block for CSApprovalExport
        return [
            'Encoder',
            'Supplier',
            'Store Branch',
            'Commiter',
            'Approver',
            'Order Number',
            'Order Date',
            'Order Status',
            'Order Request Status', // This field might not be directly available or relevant
            'Manager Approval Status', // This field might not be directly available or relevant
            'Remarks',
            'Variant',
            'Approval Action Date',
            'Commited Action Date' // Added this for completeness, as it's in the map method
        ];
    }

    public function map($order): array
    {
        // Map the model attributes to the array that will be a row in Excel
        return [
            $order->encoder?->full_name ?? 'N/A', // Access full_name accessor on User model
            $order->supplier->name ?? 'N/A',
            $order->store_branch->name ?? 'N/A',
            $order->commiter?->full_name ?? 'N/A', // Access full_name accessor on User model
            $order->approver?->full_name ?? 'N/A', // Access full_name accessor on User model
            $order->order_number,
            Carbon::parse($order->order_date)->format('Y-m-d'),
            $order->order_status,
            // Map these to N/A or empty string if not directly available on StoreOrder model
            $order->order_request_status ?? 'N/A', 
            $order->manager_approval_status ?? 'N/A', 
            $order->remarks,
            $order->variant,
            $order->approval_action_date ? Carbon::parse($order->approval_action_date)->format('Y-m-d H:i:s') : 'N/A',
            $order->commited_action_date ? Carbon::parse($order->commited_action_date)->format('Y-m-d H:i:s') : 'N/A',
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
