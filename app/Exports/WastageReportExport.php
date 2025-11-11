<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class WastageReportExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithEvents
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data)->map(function ($item, $index) {
            return array_merge(['#' => $index + 1], $item);
        });
    }

    public function headings(): array
    {
        return [
            '#',
            'Wastage #',
            'Store',
            'Item Code',
            'Item Description',
            'UoM',
            'Quantity',
            'Unit Cost',
            'Total Cost',
            'Status',
            'Reason',
            'Remarks',
            'Date',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,    // #
            'B' => 20,   // Wastage #
            'C' => 30,   // Store
            'D' => 15,   // Item Code
            'E' => 40,   // Item Description
            'F' => 10,   // UoM
            'G' => 12,   // Quantity
            'H' => 15,   // Unit Cost
            'I' => 15,   // Total Cost
            'J' => 15,   // Status
            'K' => 20,   // Reason
            'L' => 30,   // Remarks
            'M' => 20,   // Date
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Header styling
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => [
                        'rgb' => '4472C4' // Blue background
                    ]
                ],
                'font' => [
                    'color' => [
                        'rgb' => 'FFFFFF' // White text
                    ],
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
            ],
            // Data row styling
            'A2:M1000' => [
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'E0E0E0'],
                    ],
                ],
            ],
            // Specific column alignments
            'A' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'G' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
            'H' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
            'I' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Get the highest row with data
                $highestRow = $sheet->getHighestRow();
                $highestColumn = 'M'; // Updated highest column

                // Apply alternating row colors for better readability
                for ($row = 4; $row <= $highestRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('F8F9FA'); // Light gray for even rows
                    }
                }

                // Format currency columns
                $sheet->getStyle('H4:I' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('"₱"#,##0.00');

                // Format quantity columns
                $sheet->getStyle('G4:G' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                // Add title row with company info
                $sheet->insertNewRowBefore(1, 2);
                $sheet->mergeCells('A1:'.$highestColumn.'1');
                $sheet->setCellValue('A1', 'WASTAGE REPORT');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                        'color' => ['rgb' => '2C3E50'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                // Add generation date
                $sheet->mergeCells('A2:'.$highestColumn.'2');
                $sheet->setCellValue('A2', 'Generated on: ' . now()->format('F d, Y - h:i A'));
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => [
                        'size' => 11,
                        'color' => ['rgb' => '7F8C8D'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                // Update header row style to account for inserted rows
                $sheet->getStyle('A3:'.$highestColumn.'3')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 12,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => [
                            'rgb' => '4472C4' // Blue background
                        ]
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

                // Apply borders to all data
                $sheet->getStyle('A4:'.$highestColumn . $highestRow)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'E0E0E0'],
                        ],
                    ],
                ]);

                // Add summary at the bottom if there's data
                if ($highestRow > 3) {
                    $summaryRow = $highestRow + 2;

                    // Calculate totals
                    $totalQty = 0;
                    $totalCost = 0;

                    foreach ($this->data as $item) {
                        $totalQty += $item['Quantity'] ?? 0;
                        $totalCost += $item['Total Cost'] ?? 0;
                    }

                    // Add summary row
                    $sheet->setCellValue('F' . $summaryRow, 'TOTAL:');
                    $sheet->setCellValue('G' . $summaryRow, $totalQty);
                    $sheet->setCellValue('I' . $summaryRow, $totalCost);

                    // Style summary row
                    $sheet->getStyle('F' . $summaryRow . ':' . $highestColumn . $summaryRow)->applyFromArray([
                        'font' => [
                            'bold' => true,
                            'size' => 12,
                            'color' => ['rgb' => '2C3E50'],
                        ],
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => [
                                'rgb' => 'E8F4FD' // Light blue background
                            ]
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => '000000'],
                            ],
                        ],
                    ]);
                }
            },
        ];
    }
}