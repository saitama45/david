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
        $query = StoreTransaction::query()->with(['store_transaction_items', 'store_branch']);

        $user = User::rolesAndAssignedBranches();
        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if (!$this->from && !$this->to) {
            $query->where('order_date', $this->order_date);
        }

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
            $discount,
            $lineTotal,
            $netTotal,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $event->sheet->getHighestRow() + 1;

                $sheet->setCellValue('A' . $lastRow, 'TOTAL');
                $sheet->setCellValue('F' . $lastRow, $this->totalDiscount);
                $sheet->setCellValue('G' . $lastRow, $this->totalLineTotal);
                $sheet->setCellValue('H' . $lastRow, $this->totalNetTotal);

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
            },
        ];
    }
}
