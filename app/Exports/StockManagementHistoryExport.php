<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Carbon\Carbon;

class StockManagementHistoryExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithCustomStartCell, WithEvents
{
    protected $history;

    public function __construct($history)
    {
        $this->history = $history;
    }

    public function collection()
    {
        return $this->history;
    }

    public function startCell(): string
    {
        return 'A2'; // Data headers start at row 2
    }

    public function headings(): array
    {
        return [
            'Id',
            'Ref No.',
            'Quantity Change',
            'Action',
            'Cost Center',
            'Unit Cost',
            'Total Cost',
            'Transaction Date',
            'Running SOH',
            'Remarks',
        ];
    }

    public function map($row): array
    {
        $action = $this->formatAction($row->action);
        $sign = ($action === 'OUT') ? '-' : '+';
        $quantityChange = $sign . number_format($row->quantity, 2);

        return [
            $row->id,
            $row->display_ref_no ?? 'N/a',
            $quantityChange,
            $action,
            $row->cost_center->name ?? "N/a",
            $row->unit_cost,
            $row->total_cost,
            $row->transaction_date ? Carbon::parse($row->transaction_date)->format('F j, Y') : 'N/a',
            $row->running_soh,
            ($row->remarks && trim(str_replace('testing', '', str_ireplace('testing', '', $row->remarks)))) 
                ? trim(str_replace('testing', '', str_ireplace('testing', '', $row->remarks))) 
                : "None",
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestColumn = $sheet->getHighestColumn();

                // Row 1: Report Title
                $sheet->mergeCells('A1:' . $highestColumn . '1');
                $sheet->setCellValue('A1', 'Stock Management History Report');

                // Apply styling to report title
                $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => '4472C4', // Blue background
                        ],
                    ],
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                        'color' => [
                            'rgb' => 'FFFFFF',
                        ],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                // Apply styling to main headers (Row 2)
                $sheet->getStyle('A2:' . $highestColumn . '2')->applyFromArray([
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
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);

                // Set row heights
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getRowDimension(2)->setRowHeight(20);
            },
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();
        $dataStartRow = 3; // Data starts from row 3

        // Apply borders to all data rows
        $sheet->getStyle('A' . $dataStartRow . ':' . $highestColumn . $highestRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E0E0E0'],
                ],
            ],
        ]);

        // Alignment for specific columns
        // Id (A) - Center
        $sheet->getStyle('A' . $dataStartRow . ':A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Ref No. (B) - Left
        $sheet->getStyle('B' . $dataStartRow . ':B' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Quantity Change (C) - Right
        $sheet->getStyle('C' . $dataStartRow . ':C' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Action (D) - Center
        $sheet->getStyle('D' . $dataStartRow . ':D' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Unit Cost (F), Total Cost (G), Running SOH (I) - Right align and Number format
        $sheet->getStyle('F' . $dataStartRow . ':G' . $highestRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1],
        ]);
        
        $sheet->getStyle('I' . $dataStartRow . ':I' . $highestRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1],
            'font' => ['bold' => true],
        ]);

        return $sheet;
    }

    protected function formatAction($action)
    {
        if ($action === 'add' || $action === 'add_quantity') {
            return 'IN';
        } else if ($action === 'out' || $action === 'deduct' || $action === 'log_usage') {
            return 'OUT';
        }
        return strtoupper(str_replace('_', ' ', $action));
    }
}
