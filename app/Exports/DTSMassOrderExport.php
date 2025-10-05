<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\RichText\Run;

class DTSMassOrderExport implements FromArray, WithStyles, WithColumnWidths, WithEvents
{
    protected $batchData;
    protected $rowTracker = [];

    public function __construct($batchData)
    {
        $this->batchData = $batchData;
    }

    public function array(): array
    {
        // Check variant type
        if ($this->batchData['variant'] === 'FRUITS AND VEGETABLES') {
            return $this->arrayForFruitsAndVegetables();
        } else {
            return $this->arrayForIceCreamSalmon();
        }
    }

    private function arrayForIceCreamSalmon(): array
    {
        $data = [];
        $currentRow = 1;

        // Batch Info Header (Blue gradient background)
        $data[] = ['BATCH NUMBER', $this->batchData['batch_number'], 'VARIANT', $this->batchData['variant']];
        $this->rowTracker['batch_header'] = $currentRow;
        $currentRow++;

        $data[] = ['STATUS', strtoupper($this->batchData['status']), 'DATE RANGE', $this->batchData['date_range']];
        $currentRow++;

        $data[] = ['CREATED BY', $this->batchData['encoder'], 'CREATED AT', $this->batchData['created_at']];
        $this->rowTracker['batch_end'] = $currentRow;
        $currentRow++;

        // Table Headers - First Row (Gray background)
        $this->rowTracker['table_header_1'] = $currentRow;
        $data[] = ['ITEM CODE', 'ITEM DESCRIPTION', 'UOM'];
        $currentRow++;

        // Table Headers - Second Row (Item values)
        $this->rowTracker['table_header_2'] = $currentRow;
        $data[] = [
            $this->batchData['sap_item']['item_code'] ?? '',
            $this->batchData['sap_item']['item_description'] ?? '',
            $this->batchData['sap_item']['alt_uom'] ?? ''
        ];
        $currentRow++;

        // Order details by date
        $this->rowTracker['date_sections'] = [];
        foreach ($this->batchData['dates'] as $dateInfo) {
            $dateSection = ['start' => $currentRow];

            // Date header (Gray background)
            $dateSection['header'] = $currentRow;
            $data[] = [$dateInfo['display'], '', ''];
            $currentRow++;

            // Column headers for stores (Light gray background) - merged cells
            $dateSection['column_header'] = $currentRow;
            $data[] = ['Store Name', '', 'Quantity'];
            $currentRow++;

            // Stores for this date
            $dateSection['stores_start'] = $currentRow;
            $dateSection['store_data'] = [];
            foreach ($dateInfo['stores'] as $store) {
                $dateSection['store_data'][] = [
                    'row' => $currentRow,
                    'name' => $store['name'],
                    'brand_code' => $store['brand_code'] ?? '',
                    'address' => $store['complete_address'] ?? ''
                ];

                // For now, just put the name, we'll use RichText in AfterSheet
                $storeName = $store['name'];
                if (!empty($store['brand_code'])) $storeName .= "\n" . $store['brand_code'];
                if (!empty($store['complete_address'])) $storeName .= "\n" . $store['complete_address'];

                $data[] = [
                    $storeName,
                    '',
                    $store['quantity']
                ];
                $currentRow++;
            }
            $dateSection['stores_end'] = $currentRow - 1;

            // Day total (Blue background)
            $dateSection['total'] = $currentRow;
            $data[] = ['TOTAL', '', $dateInfo['total']];
            $dateSection['end'] = $currentRow;
            $currentRow++;

            $this->rowTracker['date_sections'][] = $dateSection;
        }

        // Grand total (Dark gray/black background with white text)
        $this->rowTracker['grand_total'] = $currentRow;
        $data[] = ['GRAND TOTAL', '', $this->batchData['grand_total']];

        return $data;
    }

    private function arrayForFruitsAndVegetables(): array
    {
        $data = [];
        $currentRow = 1;

        // Batch Info Header
        $data[] = ['BATCH NUMBER', $this->batchData['batch_number'], 'VARIANT', $this->batchData['variant']];
        $this->rowTracker['batch_header'] = $currentRow;
        $currentRow++;

        $data[] = ['STATUS', strtoupper($this->batchData['status']), 'DATE RANGE', $this->batchData['date_range']];
        $currentRow++;

        $data[] = ['CREATED BY', $this->batchData['encoder'], 'CREATED AT', $this->batchData['created_at']];
        $this->rowTracker['batch_end'] = $currentRow;
        $currentRow++;

        // Calculate store header structure
        $stores = $this->batchData['stores'];
        $dates = $this->batchData['dates'];

        // Helper function to check delivery schedule
        $hasDeliverySchedule = function($store, $dateObj) {
            if (!isset($store['delivery_schedule_ids']) || !isset($dateObj['delivery_schedule_id'])) {
                return false;
            }
            return in_array($dateObj['delivery_schedule_id'], $store['delivery_schedule_ids']);
        };

        // First header row: ITEM CODE, ITEM NAME, UOM, PRICE, then store names (spanning their delivery dates)
        $this->rowTracker['table_header_row1'] = $currentRow;
        $headerRow1 = ['ITEM CODE', 'ITEM NAME', 'UOM', 'PRICE'];
        $this->rowTracker['store_spans'] = [];

        foreach ($stores as $store) {
            $datesForStore = array_filter($dates, function($dateObj) use ($store, $hasDeliverySchedule) {
                return $hasDeliverySchedule($store, $dateObj);
            });
            $colspan = count($datesForStore);

            $this->rowTracker['store_spans'][] = [
                'name' => $store['name'],
                'brand_code' => $store['brand_code'] ?? '',
                'address' => $store['complete_address'] ?? '',
                'colspan' => $colspan,
                'start_col' => count($headerRow1)
            ];

            // Add store name to header (will handle colspan in AfterSheet)
            $storeName = $store['name'];
            if (!empty($store['brand_code'])) $storeName .= "\n" . $store['brand_code'];
            if (!empty($store['complete_address'])) $storeName .= "\n" . $store['complete_address'];

            $headerRow1[] = $storeName;
            for ($i = 1; $i < $colspan; $i++) {
                $headerRow1[] = ''; // Placeholder for merged cells
            }
        }

        $headerRow1[] = 'TOTAL ORDER';
        $headerRow1[] = 'BUFFER';
        $headerRow1[] = 'TOTAL PO';
        $headerRow1[] = 'TOTAL PRICE';

        $data[] = $headerRow1;
        $currentRow++;

        // Second header row: dates under each store
        $this->rowTracker['table_header_row2'] = $currentRow;
        $headerRow2 = ['', '', '', '']; // Empty for rowspan columns

        foreach ($stores as $store) {
            $datesForStore = array_filter($dates, function($dateObj) use ($store, $hasDeliverySchedule) {
                return $hasDeliverySchedule($store, $dateObj);
            });

            foreach ($datesForStore as $dateObj) {
                $dateDisplay = $dateObj['day_of_week'] . "\n" . explode('- ', $dateObj['display'])[1];
                $headerRow2[] = $dateDisplay;
            }
        }

        $headerRow2[] = ''; // Empty for rowspan columns
        $headerRow2[] = '';
        $headerRow2[] = '';
        $headerRow2[] = '';

        $data[] = $headerRow2;
        $this->rowTracker['table_start'] = $currentRow;
        $currentRow++;

        // Item rows
        $this->rowTracker['item_rows'] = ['start' => $currentRow];

        foreach ($this->batchData['supplier_items'] as $item) {
            $row = [$item['item_code'], $item['item_name'], $item['uom'], $item['price']];

            $totalOrder = 0;

            // Add quantity cells for each store's dates
            foreach ($stores as $store) {
                $datesForStore = array_filter($dates, function($dateObj) use ($store, $hasDeliverySchedule) {
                    return $hasDeliverySchedule($store, $dateObj);
                });

                foreach ($datesForStore as $dateObj) {
                    $qty = $this->batchData['orders'][$item['id']][$dateObj['date']][$store['id']] ?? 0;
                    $row[] = $qty;
                    $totalOrder += $qty;
                }
            }

            $buffer = 10;
            $totalPO = $totalOrder * 1.1;
            $totalPrice = $totalPO * $item['price'];

            $row[] = $totalOrder;
            $row[] = $buffer . '%';
            $row[] = $totalPO;
            $row[] = $totalPrice;

            $data[] = $row;
            $currentRow++;
        }

        $this->rowTracker['item_rows']['end'] = $currentRow - 1;

        // Grand Total Row
        $this->rowTracker['grand_total'] = $currentRow;
        $grandTotalRow = ['TOTAL PRICE', '', '', ''];

        // Count total date columns
        $totalDateCols = 0;
        foreach ($stores as $store) {
            $datesForStore = array_filter($dates, function($dateObj) use ($store, $hasDeliverySchedule) {
                return $hasDeliverySchedule($store, $dateObj);
            });
            $totalDateCols += count($datesForStore);
        }

        // Empty cells for dates and calculation columns
        for ($i = 0; $i < $totalDateCols + 3; $i++) {
            $grandTotalRow[] = '';
        }

        // Calculate grand total price
        $grandTotal = 0;
        foreach ($this->batchData['supplier_items'] as $item) {
            $totalOrder = 0;
            foreach ($stores as $store) {
                $datesForStore = array_filter($dates, function($dateObj) use ($store, $hasDeliverySchedule) {
                    return $hasDeliverySchedule($store, $dateObj);
                });
                foreach ($datesForStore as $dateObj) {
                    $totalOrder += $this->batchData['orders'][$item['id']][$dateObj['date']][$store['id']] ?? 0;
                }
            }
            $totalPO = $totalOrder * 1.1;
            $grandTotal += $totalPO * $item['price'];
        }

        $grandTotalRow[] = $grandTotal;
        $data[] = $grandTotalRow;

        return $data;
    }

    public function styles(Worksheet $sheet)
    {
        if ($this->batchData['variant'] === 'FRUITS AND VEGETABLES') {
            return $this->stylesForFruitsAndVegetables($sheet);
        } else {
            return $this->stylesForIceCreamSalmon($sheet);
        }
    }

    private function stylesForIceCreamSalmon(Worksheet $sheet)
    {
        $styles = [];
        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D1D5DB']
                ]
            ]
        ];

        // Batch Info Header Rows (Blue gradient - rows 1-3)
        for ($row = $this->rowTracker['batch_header']; $row <= $this->rowTracker['batch_end']; $row++) {
            $styles[$row] = [
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'DBEAFE']], // Blue-100
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'BFDBFE']
                    ]
                ]
            ];
        }

        // Table Header Row 1 (Gray background)
        $styles[$this->rowTracker['table_header_1']] = [
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F3F4F6']], // Gray-100
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            'borders' => $borderStyle['borders']
        ];

        // Table Header Row 2
        $styles[$this->rowTracker['table_header_2']] = [
            'font' => ['bold' => false, 'size' => 10],
            'borders' => $borderStyle['borders']
        ];

        // Date sections styling
        foreach ($this->rowTracker['date_sections'] as $section) {
            // Date header row - styled in AfterSheet event for font size 16
            // Just apply border here
            $styles[$section['header']] = [
                'borders' => $borderStyle['borders']
            ];

            // Column header row (Gray-50 background) - normal size
            $styles[$section['column_header']] = [
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F9FAFB']], // Gray-50
                'borders' => $borderStyle['borders']
            ];

            // Store rows
            for ($row = $section['stores_start']; $row <= $section['stores_end']; $row++) {
                $styles[$row] = [
                    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                    'borders' => $borderStyle['borders']
                ];
            }

            // Day total row (Blue-50 background)
            $styles[$section['total']] = [
                'font' => ['bold' => true, 'size' => 10],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'EFF6FF']], // Blue-50
                'borders' => $borderStyle['borders']
            ];
        }

        // Grand Total Row (Dark gray with white text)
        $styles[$this->rowTracker['grand_total']] = [
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '374151']], // Gray-700
            'borders' => $borderStyle['borders']
        ];

        return $styles;
    }

    private function stylesForFruitsAndVegetables(Worksheet $sheet)
    {
        $styles = [];
        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D1D5DB']
                ]
            ]
        ];

        // Batch Info Header Rows
        for ($row = $this->rowTracker['batch_header']; $row <= $this->rowTracker['batch_end']; $row++) {
            $styles[$row] = [
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'DBEAFE']],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'BFDBFE']
                    ]
                ]
            ];
        }

        // Table Header Rows
        $styles[$this->rowTracker['table_header_row1']] = [
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F3F4F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => $borderStyle['borders']
        ];

        $styles[$this->rowTracker['table_header_row2']] = [
            'font' => ['bold' => true, 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'E5E7EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => $borderStyle['borders']
        ];

        // Item rows
        if (isset($this->rowTracker['item_rows'])) {
            for ($row = $this->rowTracker['item_rows']['start']; $row <= $this->rowTracker['item_rows']['end']; $row++) {
                $styles[$row] = [
                    'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                    'borders' => $borderStyle['borders']
                ];
            }
        }

        // Grand Total Row
        $styles[$this->rowTracker['grand_total']] = [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '374151']],
            'borders' => $borderStyle['borders']
        ];

        return $styles;
    }

    public function columnWidths(): array
    {
        if ($this->batchData['variant'] === 'FRUITS AND VEGETABLES') {
            return []; // Dynamic widths will be set in AfterSheet
        } else {
            return [
                'A' => 45,
                'B' => 25,
                'C' => 15
            ];
        }
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                if ($this->batchData['variant'] === 'FRUITS AND VEGETABLES') {
                    $this->applyFruitsAndVegetablesFormatting($sheet);
                } else {
                    $this->applyIceCreamSalmonFormatting($sheet);
                }
            },
        ];
    }

    private function applyIceCreamSalmonFormatting($sheet)
    {

                // Apply font size 16 directly to date header rows
                foreach ($this->rowTracker['date_sections'] as $section) {
                    $row = $section['header'];

                    $cellRange = "A{$row}:C{$row}";

                    // Set font directly
                    $sheet->getStyle($cellRange)->getFont()->setBold(true)->setSize(16);

                    // Set fill color
                    $sheet->getStyle($cellRange)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('E5E7EB');

                    // Set borders
                    $sheet->getStyle($cellRange)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)
                        ->getColor()->setRGB('D1D5DB');

                    $sheet->getRowDimension($row)->setRowHeight(25);

                    // Apply RichText formatting to store cells (bold name and brand_code, regular address)
                    if (isset($section['store_data'])) {
                        foreach ($section['store_data'] as $storeInfo) {
                            $storeRow = $storeInfo['row'];
                            $richText = new RichText();

                            // Store name - bold
                            $namePart = $richText->createTextRun($storeInfo['name']);
                            $namePart->getFont()->setBold(true);

                            // Brand code - bold
                            if (!empty($storeInfo['brand_code'])) {
                                $richText->createText("\n");
                                $brandPart = $richText->createTextRun($storeInfo['brand_code']);
                                $brandPart->getFont()->setBold(true);
                            }

                            // Address - regular (not bold)
                            if (!empty($storeInfo['address'])) {
                                $richText->createText("\n");
                                $addressPart = $richText->createTextRun($storeInfo['address']);
                                $addressPart->getFont()->setBold(false);
                            }

                            $sheet->getCell("A{$storeRow}")->setValue($richText);
                        }
                    }
                }
    }

    private function applyFruitsAndVegetablesFormatting($sheet)
    {
        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(15); // ITEM CODE
        $sheet->getColumnDimension('B')->setWidth(30); // ITEM NAME
        $sheet->getColumnDimension('C')->setWidth(10); // UOM
        $sheet->getColumnDimension('D')->setWidth(10); // PRICE

        // Merge cells for rowspan columns in header row 1
        $headerRow1 = $this->rowTracker['table_header_row1'];
        $headerRow2 = $this->rowTracker['table_header_row2'];

        // Merge ITEM CODE, ITEM NAME, UOM, PRICE (columns A-D)
        $sheet->mergeCells("A{$headerRow1}:A{$headerRow2}");
        $sheet->mergeCells("B{$headerRow1}:B{$headerRow2}");
        $sheet->mergeCells("C{$headerRow1}:C{$headerRow2}");
        $sheet->mergeCells("D{$headerRow1}:D{$headerRow2}");

        // Merge store header cells based on colspan
        $columnIndex = 5; // Start after PRICE column (E)
        $maxRowHeight = 15; // Track maximum row height needed

        foreach ($this->rowTracker['store_spans'] as $storeSpan) {
            $startCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $endCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + $storeSpan['colspan'] - 1);

            // Always merge cells (even if colspan is 1, to handle rowspan)
            if ($storeSpan['colspan'] > 1) {
                $sheet->mergeCells("{$startCol}{$headerRow1}:{$endCol}{$headerRow1}");
            }

            // Apply RichText to store header (bold store name and brand_code)
            $richText = new RichText();
            $namePart = $richText->createTextRun($storeSpan['name']);
            $namePart->getFont()->setBold(true);

            $lineCount = 1; // Start with store name

            if (!empty($storeSpan['brand_code'])) {
                $richText->createText("\n");
                $brandPart = $richText->createTextRun($storeSpan['brand_code']);
                $brandPart->getFont()->setBold(true);
                $lineCount++;
            }

            if (!empty($storeSpan['address'])) {
                $richText->createText("\n");
                $addressPart = $richText->createTextRun($storeSpan['address']);
                $addressPart->getFont()->setBold(false);

                // Calculate wrapped lines for address (estimate based on column width)
                $columnWidth = $storeSpan['colspan'] * 12; // Each date column is 12 wide
                $addressLength = strlen($storeSpan['address']);
                $charsPerLine = max(1, floor($columnWidth * 1.0)); // Approximate chars per line
                $addressLines = ceil($addressLength / $charsPerLine);
                $lineCount += $addressLines;
            }

            // Calculate row height (approximately 15 points per line)
            $calculatedHeight = $lineCount * 15;
            $maxRowHeight = max($maxRowHeight, $calculatedHeight);

            $sheet->getCell("{$startCol}{$headerRow1}")->setValue($richText);
            $sheet->getStyle("{$startCol}{$headerRow1}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);

            // Set blue background for store headers
            $sheet->getStyle("{$startCol}{$headerRow1}:{$endCol}{$headerRow1}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('DBEAFE');

            // Set column widths for date columns
            for ($i = 0; $i < $storeSpan['colspan']; $i++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + $i);
                $sheet->getColumnDimension($col)->setWidth(12);
            }

            $columnIndex += $storeSpan['colspan'];
        }

        // Apply the maximum calculated row height to header row 1
        $sheet->getRowDimension($headerRow1)->setRowHeight($maxRowHeight);

        // Merge TOTAL ORDER, BUFFER, TOTAL PO, TOTAL PRICE columns
        $totalOrderCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
        $bufferCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1);
        $totalPOCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 2);
        $totalPriceCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 3);

        $sheet->mergeCells("{$totalOrderCol}{$headerRow1}:{$totalOrderCol}{$headerRow2}");
        $sheet->mergeCells("{$bufferCol}{$headerRow1}:{$bufferCol}{$headerRow2}");
        $sheet->mergeCells("{$totalPOCol}{$headerRow1}:{$totalPOCol}{$headerRow2}");
        $sheet->mergeCells("{$totalPriceCol}{$headerRow1}:{$totalPriceCol}{$headerRow2}");

        // Set widths for calculation columns
        $sheet->getColumnDimension($totalOrderCol)->setWidth(12);
        $sheet->getColumnDimension($bufferCol)->setWidth(10);
        $sheet->getColumnDimension($totalPOCol)->setWidth(12);
        $sheet->getColumnDimension($totalPriceCol)->setWidth(15);

        // Apply yellow background to TOTAL ORDER, BUFFER, TOTAL PO columns
        $sheet->getStyle("{$totalOrderCol}{$headerRow1}:{$totalPOCol}{$headerRow2}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FEF3C7');

        // Apply green background to TOTAL PRICE column
        $sheet->getStyle("{$totalPriceCol}{$headerRow1}:{$totalPriceCol}{$headerRow2}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D1FAE5');

        // Merge grand total row cells
        $grandTotalRow = $this->rowTracker['grand_total'];
        $sheet->mergeCells("A{$grandTotalRow}:D{$grandTotalRow}");

        // Calculate where to merge empty cells in grand total
        $lastDataCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 2); // before TOTAL PRICE
        $sheet->mergeCells("E{$grandTotalRow}:{$lastDataCol}{$grandTotalRow}");
    }
}
