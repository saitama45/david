<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
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

class ActualCostCOGSReportExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle, WithCustomStartCell, WithEvents
{
    protected $data;
    protected $filters;

    public function __construct($data, $filters = [])
    {
        $this->data = $data;
        $this->filters = $filters;
        // Ensure 'year' and 'month' are set with defaults if not present
        $this->filters['year'] = $this->filters['year'] ?? Carbon::now()->year;
        $this->filters['month'] = $this->filters['month'] ?? Carbon::now()->month;
    }

    public function collection()
    {
        return $this->data;
    }

    public function title(): string
    {
        return 'Actual Cost / COGS Report';
    }

    public function startCell(): string
    {
        return 'A5'; // Data starts after header rows
    }

    public function headings(): array
    {
        // Headings are handled by events
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Define the columns for the report
                $staticHeaders = [
                    'Store Branch',
                    'Item Code',
                    'Item Description',
                    'UoM',
                    'Unit Cost',
                ];
                $pairedHeaders = [
                    'Beginning Inventory',
                    'Deliveries',
                    'Interco',
                    'Ending Inventory',
                ];
                $singleHeaders = [
                    'Actual Cost',
                ];

                // Calculate total number of data columns
                $totalDataColumns = count($staticHeaders) + (count($pairedHeaders) * 2) + count($singleHeaders);
                $highestColumn = Coordinate::stringFromColumnIndex($totalDataColumns);

                // Row 1: Report Title
                $sheet->mergeCells('A1:' . $highestColumn . '1');
                $sheet->setCellValue('A1', 'Actual Cost / COGS Report');

                // Row 2: Date Range and Generated info
                $monthName = Carbon::create()->month($this->filters['month'])->format('F');
                $dateRange = 'Month: ' . $monthName . ' ' . ($this->filters['year'] ?? 'N/A');
                $generatedInfo = 'Generated on: ' . now()->format('Y-m-d H:i:s');

                $middleColumnIndex = intval($totalDataColumns / 2);
                $middleColumn = Coordinate::stringFromColumnIndex($middleColumnIndex);
                $startGeneratedColumn = Coordinate::stringFromColumnIndex($middleColumnIndex + 1);

                $sheet->mergeCells('A2:' . $middleColumn . '2');
                $sheet->setCellValue('A2', $dateRange);
                $sheet->mergeCells($startGeneratedColumn . '2:' . $highestColumn . '2');
                $sheet->setCellValue($startGeneratedColumn . '2', $generatedInfo);

                // Row 3: Main Headers
                $currentColIndex = 1;
                foreach ($staticHeaders as $header) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColIndex) . '3', $header);
                    $currentColIndex++;
                }

                foreach ($pairedHeaders as $header) {
                    $startCol = Coordinate::stringFromColumnIndex($currentColIndex);
                    $endCol = Coordinate::stringFromColumnIndex($currentColIndex + 1);
                    $sheet->mergeCells($startCol . '3:' . $endCol . '3');
                    $sheet->setCellValue($startCol . '3', $header);
                    $currentColIndex += 2;
                }
                
                foreach ($singleHeaders as $header) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColIndex) . '3', $header);
                    $currentColIndex++;
                }

                // Row 4: Sub-headers (Qty and Value)
                $currentColIndex = 1;
                // Static headers have no sub-headers
                for ($i = 0; $i < count($staticHeaders); $i++) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColIndex) . '4', '');
                    $currentColIndex++;
                }

                // Paired headers get Qty/Value sub-headers
                foreach ($pairedHeaders as $header) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColIndex) . '4', 'Qty');
                    $currentColIndex++;
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColIndex) . '4', 'Value');
                    $currentColIndex++;
                }

                // Single headers have no sub-headers
                for ($i = 0; $i < count($singleHeaders); $i++) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColIndex) . '4', '');
                    $currentColIndex++;
                }

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

                // Apply styling to date range and generated info
                $sheet->getStyle('A2:' . $highestColumn . '2')->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => 'E8F0FE', // Light blue background
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

                // Apply styling to main headers (Row 3)
                $sheet->getStyle('A3:' . $highestColumn . '3')->applyFromArray([
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

                // Apply styling to sub-headers (Row 4)
                $sheet->getStyle('A4:' . $highestColumn . '4')->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => 'B8D4F1', // Lighter sky blue
                        ],
                    ],
                    'font' => [
                        'bold' => true,
                        'size' => 11,
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

                // Set column widths
                $sheet->getColumnDimension('A')->setWidth(25);  // Store Branch
                $sheet->getColumnDimension('B')->setWidth(15);  // Item Code
                $sheet->getColumnDimension('C')->setWidth(40);  // Item Description
                $sheet->getColumnDimension('D')->setWidth(10);  // UoM
                $sheet->getColumnDimension('E')->setWidth(15);  // Unit Cost

                // Paired columns widths
                $currentColIndex = 6; // F
                for ($i = 0; $i < count($pairedHeaders); $i++) {
                    $qtyCol = Coordinate::stringFromColumnIndex($currentColIndex);
                    $valueCol = Coordinate::stringFromColumnIndex($currentColIndex + 1);
                    $sheet->getColumnDimension($qtyCol)->setWidth(15);
                    $sheet->getColumnDimension($valueCol)->setWidth(20);
                    $currentColIndex += 2;
                }
                
                // Single column width for Actual Cost
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($currentColIndex))->setWidth(20);


                // Set row heights
                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getRowDimension(2)->setRowHeight(25);
                $sheet->getRowDimension(3)->setRowHeight(20);
                $sheet->getRowDimension(4)->setRowHeight(20);
            },
        ];
    }

    /**
     * @param mixed $item
     * @return array
     */
    public function map($item): array
    {
        // Ensure item is an array
        $item = (array) $item;

        return [
            $item['store_branch'],
            $item['item_code'],
            $item['item_description'],
            $item['uom'],
            number_format($item['unit_cost'], 2),
            number_format($item['beginning_inventory'], 2),
            number_format($item['beginning_value'], 2),
            number_format($item['deliveries'], 2),
            number_format($item['deliveries_value'], 2),
            number_format($item['interco'], 2),
            number_format($item['interco_value'], 2),
            number_format($item['ending_inventory'], 2),
            number_format($item['ending_value'], 2),
            number_format($item['actual_cost'], 2),
        ];
    }

    /**
     * Apply styles to the worksheet (for data rows)
     */
    public function styles(Worksheet $sheet)
    {
        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();
        $dataStartRow = 5; // Data starts from row 5

        // Apply borders to all data rows
        $sheet->getStyle('A' . $dataStartRow . ':' . $highestColumn . $highestRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E0E0E0'],
                ],
            ],
        ]);

        // Apply number formatting and alignment for numeric columns
        $currentColIndex = 5; // Unit Cost (E)
        $sheet->getStyle(Coordinate::stringFromColumnIndex($currentColIndex) . $dataStartRow . ':' . Coordinate::stringFromColumnIndex($currentColIndex) . $highestRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1],
        ]);
        $currentColIndex++; // F

        // Paired columns (Qty/Value)
        $pairedHeadersCount = 4; // Beginning, Deliveries, Interco, Ending
        for ($i = 0; $i < $pairedHeadersCount; $i++) {
            $qtyCol = Coordinate::stringFromColumnIndex($currentColIndex);
            $valueCol = Coordinate::stringFromColumnIndex($currentColIndex + 1);

            // Qty columns - center align, number format
            $sheet->getStyle($qtyCol . $dataStartRow . ':' . $qtyCol . $highestRow)->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'numberFormat' => ['formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1],
            ]);
            // Value columns - right align, currency format
            $sheet->getStyle($valueCol . $dataStartRow . ':' . $valueCol . $highestRow)->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE], // Assuming currency
            ]);
            $currentColIndex += 2;
        }

        // Actual Cost column - right align, currency format
        $actualCostCol = Coordinate::stringFromColumnIndex($currentColIndex);
        $sheet->getStyle($actualCostCol . $dataStartRow . ':' . $actualCostCol . $highestRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE], // Assuming currency
            'font' => ['bold' => true], // Make actual cost bold
        ]);

        return $sheet;
    }
}