<?php

namespace App\Exports;

use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class StoreTransactionExport implements FromQuery, WithHeadings, WithMapping, WithEvents
{
    protected $from;
    protected $to;
    protected $branchId;
    protected $order_date;
    protected $search;
    protected $totalDiscount = 0;
    protected $totalLineTotal = 0;
    protected $totalNetTotal = 0;

    public function __construct($search = null, $branchId = null, $from = null, $to = null, $order_date = null)
    {
        $this->search = $search;
        $this->branchId = $branchId;
        $this->from = $from;
        $this->to = $to;
        $this->order_date = $order_date;
    }

    public function query()
    {
        // Eager load store_branch and store_transaction_items
        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch']);

        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if (!$this->from && !$this->to && $this->order_date) { // Only apply order_date filter if from/to are not set
            $query->where('order_date', $this->order_date);
        }

        if ($this->from && $this->to) {
            $query->whereBetween('order_date', [$this->from, $this->to]);
        }

        if ($this->branchId)
            $query->where('store_branch_id', $this->branchId);

        if ($this->search)
            $query->where('receipt_number', 'like', "%$this->search%");

        return $query->orderBy('order_date', 'asc'); // Ordering by date for better summary presentation
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
        // Summing up values from related store_transaction_items
        $discount = $row->store_transaction_items->sum('discount');
        $lineTotal = $row->store_transaction_items->sum('line_total');
        $netTotal = $row->store_transaction_items->sum('net_total');

        $this->totalDiscount += $discount;
        $this->totalLineTotal += $lineTotal;
        $this->totalNetTotal += $netTotal;

        return [
            $row->store_branch->location_code,
            $row->receipt_number,
            $row->tim_number,
            $row->posted,
            $row->order_date,
            number_format($discount, 2), // Format for currency
            number_format($lineTotal, 2), // Format for currency
            number_format($netTotal, 2), // Format for currency
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $event->sheet->getHighestRow() + 1;

                $sheet->setCellValue('A' . $lastRow, 'TOTAL');
                $sheet->setCellValue('F' . $lastRow, number_format($this->totalDiscount, 2));
                $sheet->setCellValue('G' . $lastRow, number_format($this->totalLineTotal, 2));
                $sheet->setCellValue('H' . $lastRow, number_format($this->totalNetTotal, 2));

                $styleArray = [
                    'font' => [
                        'bold' => true,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E0E0E0'],
                    ],
                    'borders' => [
                        'top' => [
                            'borderStyle' => Border::BORDER_THIN,
                        ],
                        'bottom' => [
                            'borderStyle' => Border::BORDER_DOUBLE,
                        ],
                    ],
                ];

                $sheet->getStyle('A' . $lastRow . ':H' . $lastRow)->applyFromArray($styleArray);
                // Apply auto size to columns for better readability
                foreach (range('A', $sheet->getHighestColumn()) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            },
        ];
    }
}
