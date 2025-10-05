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

    public function styles(Worksheet $sheet)
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

    public function columnWidths(): array
    {
        return [
            'A' => 45,
            'B' => 25,
            'C' => 15
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

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
            },
        ];
    }
}
