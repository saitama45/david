<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Maatwebsite\Excel\Concerns\WithEvents;

class DeliveryReportExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle, WithCustomStartCell, WithEvents
{
    protected $deliveryData;
    protected $filters;

    public function __construct($deliveryData, $filters = [])
    {
        $this->deliveryData = $deliveryData;
        $this->filters = $filters;
    }

    public function collection()
    {
        return collect($this->deliveryData);
    }

    public function title(): string
    {
        return 'Delivery Report';
    }

    public function startCell(): string
    {
        return 'A5'; // Start data after header rows
    }

    public function headings(): array
    {
        // We'll handle headers manually in events method
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Row 1: Report Title (merged across all columns A-O)
                $sheet->mergeCells('A1:O1');
                $sheet->setCellValue('A1', 'Delivery Report');

                // Row 2: Date Range and Generated info
                $dateRange = 'Date Range: ' . ($this->filters['date_from'] ?? 'N/A') . ' to ' . ($this->filters['date_to'] ?? 'N/A');
                $generatedInfo = 'Generated on: ' . now()->format('Y-m-d H:i:s');

                // Merge cells for date range (columns A to G)
                $sheet->mergeCells('A2:G2');
                $sheet->setCellValue('A2', $dateRange);

                // Merge cells for generated info (columns H to O)
                $sheet->mergeCells('H2:O2');
                $sheet->setCellValue('H2', $generatedInfo);

                // Row 3: Column Headers
                $sheet->setCellValue('A3', 'Expected Delivery Date');
                $sheet->setCellValue('B3', 'Received Logged'); // Renamed from Date Received
                $sheet->setCellValue('C3', 'Store');
                $sheet->setCellValue('D3', 'Supplier Code'); // New
                $sheet->setCellValue('E3', 'Status'); // New
                $sheet->setCellValue('F3', 'Item Code');
                $sheet->setCellValue('G3', 'Item Name');
                $sheet->setCellValue('H3', 'UOM'); // New
                $sheet->setCellValue('I3', 'Order Qty');
                $sheet->setCellValue('J3', 'Committed Qty');
                $sheet->setCellValue('K3', 'Received Qty');
                $sheet->setCellValue('L3', 'Variance (Ordered vs Committed)');
                $sheet->setCellValue('M3', 'Variance (Committed vs Received)');
                $sheet->setCellValue('N3', 'SO Number');
                $sheet->setCellValue('O3', 'DR Number');

                // Apply styles to headers and title
                $sheet->getStyle('A1:O1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D4E6F1']]
                ]);

                $sheet->getStyle('A2:G2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F8FF']]
                ]);

                $sheet->getStyle('H2:O2')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F8FF']]
                ]);

                $sheet->getStyle('A3:O3')->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D3D3D3']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);

                // Apply borders to header area
                $sheet->getStyle('A1:O3')->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000']
                        ]
                    ]
                ]);
            },
        ];
    }

    public function map($item): array
    {
        $quantityOrdered = $item['quantity_ordered'] ?? 0;
        $quantityCommitted = $item['quantity_committed'] ?? 0;
        $quantityReceived = $item['quantity_received'] ?? 0;

        $varianceOrderedCommitted = abs($quantityCommitted - $quantityOrdered);
        $varianceCommittedReceived = abs($quantityReceived - $quantityCommitted);

        return [
            $item['expected_delivery_date'] ? date('Y-m-d', strtotime($item['expected_delivery_date'])) : '',
            $item['date_received'] ? date('Y-m-d', strtotime($item['date_received'])) : '',
            ($item['store_name'] ?? '') . ' (' . ($item['store_code'] ?? '') . ')',
            $item['supplier_code'] ?? '',
            strtoupper($item['status'] ?? ''),
            $item['item_code'] ?? '',
            $item['item_description'] ?? '',
            $item['uom'] ?? '',
            $quantityOrdered,
            $quantityCommitted,
            $quantityReceived,
            $varianceOrderedCommitted,
            $varianceCommittedReceived,
            $item['so_number'] ?? '',
            $item['dr_number'] ?? ''
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Apply styles to data rows starting from row 5
        $lastRow = $sheet->getHighestDataRow();

        // Apply alternating row colors
        for ($row = 5; $row <= $lastRow; $row++) {
            if ($row % 2 == 0) {
                // Even rows - light blue
                $sheet->getStyle('A' . $row . ':O' . $row)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F9FA']]
                ]);
            } else {
                // Odd rows - white
                $sheet->getStyle('A' . $row . ':O' . $row)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']]
                ]);
            }
        }

        // Apply borders to all data
        $sheet->getStyle('A5:O' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);

        // Apply number formatting to quantity columns (I, J, K, L, M)
        $sheet->getStyle('I5:M' . $lastRow)->applyFromArray([
            'numberFormat' => [
                'formatCode' => NumberFormat::FORMAT_NUMBER,
            ],
        ]);

        // Apply alignment
        // Center align date columns (A, B) and quantity columns (I, J, K, L, M) and Status (E) and UOM (H)
        $sheet->getStyle('A5:B' . $lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        $sheet->getStyle('E5:E' . $lastRow)->applyFromArray([ // Status
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        $sheet->getStyle('H5:H' . $lastRow)->applyFromArray([ // UOM
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        $sheet->getStyle('I5:M' . $lastRow)->applyFromArray([ // Quantities and Variances
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
    }
}
