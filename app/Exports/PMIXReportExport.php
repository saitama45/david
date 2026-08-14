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
use Maatwebsite\Excel\Concerns\WithEvents;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PMIXReportExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle, WithCustomStartCell, WithEvents
{
    /** Sub-columns rendered under every store (and under Total), in order. */
    private const SUB_HEADINGS = ['Qty', 'Sales', '# of Take Out', '# of Dine In'];

    private const COLUMNS_PER_STORE = 4;

    protected $pmixData;
    protected $storeColumns;
    protected $filters;

    public function __construct($pmixData, $storeColumns, $filters = [])
    {
        $this->pmixData = $pmixData;
        $this->storeColumns = $storeColumns;
        $this->filters = $filters;
    }

    public function collection()
    {
        return collect($this->pmixData);
    }

    public function title(): string
    {
        return 'PMIX Report';
    }

    public function startCell(): string
    {
        return 'A5'; // Start data after header rows
    }

    public function headings(): array
    {
        // We'll handle headers manually in the events method
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Get the highest column for the data
                $highestColumn = $sheet->getHighestColumn();
                $totalColumns = Coordinate::columnIndexFromString($highestColumn);

                // Row 1: Report Title (merged across all columns)
                $sheet->mergeCells('A1:' . $highestColumn . '1');
                $sheet->setCellValue('A1', 'PMIX Report');

                // Row 2: Date Range and Generated info
                $dateRange = 'Date Range: ' . ($this->filters['date_from'] ?? 'N/A') . ' to ' . ($this->filters['date_to'] ?? 'N/A');
                $generatedInfo = 'Generated on: ' . now()->format('Y-m-d H:i:s');

                // Merge cells for date range (columns A to middle)
                $middleColumn = Coordinate::stringFromColumnIndex(intval($totalColumns / 2));
                $sheet->mergeCells('A2:' . $middleColumn . '2');
                $sheet->setCellValue('A2', $dateRange);

                // Merge cells for generated info (middle+1 to end)
                $startGeneratedColumn = Coordinate::stringFromColumnIndex(intval($totalColumns / 2) + 1);
                $sheet->mergeCells($startGeneratedColumn . '2:' . $highestColumn . '2');
                $sheet->setCellValue($startGeneratedColumn . '2', $generatedInfo);

                // Row 3: Column Headers
                $currentColumn = 1; // Start from column A (1)
                $sheet->setCellValue('A3', 'POS Code');
                $sheet->setCellValue('B3', 'Item Description');
                $sheet->setCellValue('C3', 'Category');
                $sheet->setCellValue('D3', 'Sub Category');
                $currentColumn = 5; // Start store columns from E (5)

                // Add store headers (each spanning the per-store sub-columns)
                $storeHeaderColumns = [];
                foreach ($this->storeColumns as $storeId => $storeName) {
                    $startCol = Coordinate::stringFromColumnIndex($currentColumn);
                    $endCol = Coordinate::stringFromColumnIndex($currentColumn + self::COLUMNS_PER_STORE - 1);
                    $sheet->mergeCells($startCol . '3:' . $endCol . '3');
                    $sheet->setCellValue($startCol . '3', $storeName);
                    $storeHeaderColumns[$storeId] = ['start' => $startCol, 'end' => $endCol];
                    $currentColumn += self::COLUMNS_PER_STORE;
                }

                // Add Total header (spanning the same sub-columns)
                $totalStartCol = Coordinate::stringFromColumnIndex($currentColumn);
                $totalEndCol = Coordinate::stringFromColumnIndex($currentColumn + self::COLUMNS_PER_STORE - 1);
                $sheet->mergeCells($totalStartCol . '3:' . $totalEndCol . '3');
                $sheet->setCellValue($totalStartCol . '3', 'Total');

                // Row 4: Sub-headers (Qty, Sales, # of Take Out, # of Dine In)
                $sheet->setCellValue('A4', ''); // Empty for POS Code
                $sheet->setCellValue('B4', ''); // Empty for Item Description
                $sheet->setCellValue('C4', ''); // Empty for Category
                $sheet->setCellValue('D4', ''); // Empty for Sub Category
                $currentColumn = 5;

                // Add sub-headers for each store, then for the Total group
                foreach (array_merge(array_keys($this->storeColumns), ['__total']) as $groupKey) {
                    foreach (self::SUB_HEADINGS as $offset => $label) {
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($currentColumn + $offset) . '4', $label);
                    }
                    $currentColumn += self::COLUMNS_PER_STORE;
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
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
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
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);

                // Set column widths
                $sheet->getColumnDimension('A')->setWidth(15);  // POS Code
                $sheet->getColumnDimension('B')->setWidth(40);  // Item Description
                $sheet->getColumnDimension('C')->setWidth(20);  // Category
                $sheet->getColumnDimension('D')->setWidth(20);  // Sub Category

                // Set widths for store columns, then for the total columns
                $currentColumn = 5;
                $subColumnWidths = [12, 15, 14, 14];
                foreach (array_merge(array_keys($this->storeColumns), ['__total']) as $groupKey) {
                    foreach ($subColumnWidths as $offset => $width) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($currentColumn + $offset))->setWidth($width);
                    }
                    $currentColumn += self::COLUMNS_PER_STORE;
                }

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
        $row = [
            $item['POSCode'],
            $item['POSDescription'],
            $item['Category'],
            $item['SubCategory']
        ];

        $totalQty = 0;
        $totalSales = 0;
        $totalTakeOut = 0;
        $totalDineIn = 0;

        // Add store-specific quantity, sales and take out / dine in counts
        foreach ($this->storeColumns as $storeId => $storeName) {
            $qty = $item['stores'][$storeId]['quantity'] ?? 0;
            $sales = $item['stores'][$storeId]['sales'] ?? 0;
            $takeOut = $item['stores'][$storeId]['take_out'] ?? 0;
            $dineIn = $item['stores'][$storeId]['dine_in'] ?? 0;

            $row[] = $qty;
            $row[] = $sales;
            $row[] = $takeOut;
            $row[] = $dineIn;

            $totalQty += $qty;
            $totalSales += $sales;
            $totalTakeOut += $takeOut;
            $totalDineIn += $dineIn;
        }

        // Add totals
        $row[] = $totalQty;
        $row[] = $totalSales;
        $row[] = $totalTakeOut;
        $row[] = $totalDineIn;

        return $row;
    }

    /**
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();

        // Data starts from row 5, so we style from row 5 onwards
        $dataStartRow = 5;

        // Apply alternating row colors for better readability
        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            if ($row % 2 == 1) { // Odd rows (starting from 5)
                $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => 'F8F9FA',
                        ],
                    ],
                ]);
            }
        }

        // Apply borders to all data rows
        $sheet->getStyle('A' . $dataStartRow . ':' . $highestColumn . $highestRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => 'E0E0E0'],
                ],
            ],
        ]);

        // Style the per-store sub-columns (Qty, Sales, # of Take Out, # of Dine In)
        $columnCount = count($this->storeColumns);
        $startColumn = 5; // Store columns start from E (5)

        for ($i = 0; $i < $columnCount; $i++) {
            $groupStart = $startColumn + ($i * self::COLUMNS_PER_STORE);
            $salesCol = Coordinate::stringFromColumnIndex($groupStart + 1);

            // Center align the count columns (Qty, # of Take Out, # of Dine In)
            foreach ([0, 2, 3] as $offset) {
                $col = Coordinate::stringFromColumnIndex($groupStart + $offset);
                $sheet->getStyle($col . $dataStartRow . ':' . $col . $highestRow)->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
            }

            // Right align and format sales columns
            $sheet->getStyle($salesCol . $dataStartRow . ':' . $salesCol . $highestRow)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
                'numberFormat' => [
                    'formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
                ],
            ]);
        }

        // Style Total columns with bold formatting and yellow background
        $totalGroupStart = $startColumn + ($columnCount * self::COLUMNS_PER_STORE);
        $totalSalesCol = Coordinate::stringFromColumnIndex($totalGroupStart + 1);

        foreach ([0, 2, 3] as $offset) {
            $col = Coordinate::stringFromColumnIndex($totalGroupStart + $offset);
            $sheet->getStyle($col . $dataStartRow . ':' . $col . $highestRow)->applyFromArray([
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => [
                        'rgb' => 'FFF3CD', // Light yellow background
                    ],
                ],
            ]);
        }

        $sheet->getStyle($totalSalesCol . $dataStartRow . ':' . $totalSalesCol . $highestRow)->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
            ],
            'numberFormat' => [
                'formatCode' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'FFF3CD', // Light yellow background
                ],
            ],
        ]);

        return $sheet;
    }
}