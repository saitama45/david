<?php

namespace App\Exports;

use App\Models\StoreOrder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class IntercoReportExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $user = Auth::user();

        // Set default filters
        $filters = $this->filters;
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['interco_status'] = $filters['interco_status'] ?? 'received';

        // Get user's assigned stores
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        // Build query for interco orders with line items
        $query = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->with([
                'sendingStore',
                'store_branch',
                'store_order_items.sapMasterfile',
                'store_order_items.ordered_item_receive_dates' => fn($q) => $q->where('status', 'approved'),
                'store_order_remarks' => fn($q) => $q->where('action', 'COMMIT')
            ])
            ->whereHas('store_order_items.sapMasterfile')
            ->whereBetween('order_date', [$filters['date_from'], $filters['date_to']]);

        // Apply user permissions
        if ($assignedStoreIds->isNotEmpty()) {
            $query->where(function($q) use ($assignedStoreIds) {
                $q->whereIn('store_branch_id', $assignedStoreIds)
                  ->orWhereIn('sending_store_branch_id', $assignedStoreIds);
            });
        }

        // Apply filters
        if (!empty($filters['sending_store_id'])) {
            $query->where('sending_store_branch_id', $filters['sending_store_id']);
        }

        if (!empty($filters['receiving_store_id'])) {
            $query->where('store_branch_id', $filters['receiving_store_id']);
        }

        if (!empty($filters['interco_status'])) {
            $query->where('interco_status', $filters['interco_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('interco_number', 'like', "%{$search}%")
                  ->orWhereHas('sendingStore', fn($sq) => $sq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('store_branch', fn($rq) => $rq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('store_order_items.sapMasterfile', fn($iq) => $iq->where('ItemCode', 'like', "%{$search}%")
                                                                                                    ->orWhere('ItemDescription', 'like', "%{$search}%"));
            });
        }

        return $query->orderBy('order_date', 'desc');
    }

    public function headings(): array
    {
        return [
            'Item Code',
            'Item Description',
            'Received Qty',
            'UoM',
            'Requested Date',
            'Reason',
            'From Store',
            'To Store',
            'Interco Number',
            'Status',
            'Expiry Date',
            'Unit Cost',
            'Total Cost',
            'Shipped Date',
            'Received Date',
        ];
    }

    /**
     * @param mixed $order
     * @return array
     */
    public function map($order): array
    {
        $rows = [];

        foreach ($order->store_order_items as $item) {
            if ($item->sapMasterfile) {
                // Get shipped date from COMMIT remarks
                $shippedDate = $order->store_order_remarks->first()?->created_at;

                // Get received and expiry dates
                $approvedReceiveDates = $item->ordered_item_receive_dates
                    ->where('status', 'approved');

                $receivedDates = $approvedReceiveDates->pluck('received_date')->unique();
                $expiryDates = $approvedReceiveDates->pluck('expiry_date')->unique();

                // Calculate total received quantity for this item
                $totalReceivedQuantity = $approvedReceiveDates->sum('quantity_received');

                // Create separate rows for each received date if multiple
                if ($receivedDates->count() > 0) {
                    foreach ($receivedDates as $index => $receivedDate) {
                        $rows[] = [
                            $item->sapMasterfile->ItemCode,
                            $item->sapMasterfile->ItemDescription,
                            $totalReceivedQuantity,
                            $item->sapMasterfile->BaseUOM,
                            $order->order_date,
                            $order->interco_reason,
                            $order->sendingStore->name . ' (' . $order->sendingStore->brand_name . ')',
                            $order->store_branch->name . ' (' . $order->store_branch->brand_name . ')',
                            $order->interco_number,
                            $order->interco_status?->getLabel() ?? 'N/A',
                            $expiryDates[$index] ? Carbon::parse($expiryDates[$index])->format('Y-m-d') : '',
                            $item->cost_per_quantity,
                            $item->total_cost,
                            $shippedDate ? Carbon::parse($shippedDate)->format('Y-m-d') : '',
                            $receivedDate ? Carbon::parse($receivedDate)->format('Y-m-d') : '',
                        ];
                    }
                } else {
                    // Single row if no received dates
                    $rows[] = [
                        $item->sapMasterfile->ItemCode,
                        $item->sapMasterfile->ItemDescription,
                        $totalReceivedQuantity,
                        $item->sapMasterfile->BaseUOM,
                        $order->order_date,
                        $order->interco_reason,
                        $order->sendingStore->name . ' (' . $order->sendingStore->brand_name . ')',
                        $order->store_branch->name . ' (' . $order->store_branch->brand_name . ')',
                        $order->interco_number,
                        $order->interco_status?->getLabel() ?? 'N/A',
                        $expiryDates->first() ? Carbon::parse($expiryDates->first())->format('Y-m-d') : '',
                        $item->cost_per_quantity,
                        $item->total_cost,
                        $shippedDate ? Carbon::parse($shippedDate)->format('Y-m-d') : '',
                        '',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        // Apply header styling (sky blue background, bold text, centered)
        $sheet->getStyle('A1:P1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '87CEEB', // Sky blue background
                ],
            ],
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Set column widths for better readability
        $sheet->getColumnDimension('A')->setWidth(15);  // Item Code
        $sheet->getColumnDimension('B')->setWidth(40);  // Item Description
        $sheet->getColumnDimension('C')->setWidth(15);  // Received Qty
        $sheet->getColumnDimension('D')->setWidth(10);  // UoM
        $sheet->getColumnDimension('E')->setWidth(15);  // Requested Date
        $sheet->getColumnDimension('F')->setWidth(20);  // Reason
        $sheet->getColumnDimension('G')->setWidth(25);  // From Store
        $sheet->getColumnDimension('H')->setWidth(25);  // To Store
        $sheet->getColumnDimension('I')->setWidth(15);  // Interco Number
        $sheet->getColumnDimension('J')->setWidth(15);  // Status
        $sheet->getColumnDimension('K')->setWidth(15);  // Expiry Date
        $sheet->getColumnDimension('L')->setWidth(15);  // Unit Cost
        $sheet->getColumnDimension('M')->setWidth(15);  // Total Cost
        $sheet->getColumnDimension('N')->setWidth(15);  // Shipped Date
        $sheet->getColumnDimension('O')->setWidth(15);  // Received Date
    }
}