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
            return [
                '#' => $index + 1,
                'wastage_no' => $item['wastage_no'] ?? 'N/A',
                'store' => $item['store'] ?? 'N/A',
                'total_qty' => number_format($item['total_qty'] ?? 0, 2, '.', ','),
                'items_count' => $item['items_count'] ?? 0,
                'total_cost' => $item['total_cost'] ?? 0,
                'status' => $item['status'] ?? 'N/A',
                'reason' => $item['reason'] ?? 'N/A',
                'created_at' => $item['created_at'] ?? 'N/A',
            ];
        });
    }

    public function headings(): array
    {
        return [
            '#',
            'Wastage #',
            'Store',
            'Total Qty',
            'Items',
            'Total Cost',
            'Status',
            'Reason',
            'Date',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,    // #
            'B' => 20,   // Wastage #
            'C' => 30,   // Store
            'D' => 12,   // Total Qty
            'E' => 8,    // Items
            'F' => 15,   // Total Cost
            'G' => 15,   // Status
            'H' => 20,   // Reason
            'I' => 18,   // Date
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
            'A2:I1000' => [
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
            'D' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'E' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'F' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
            'H' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]],
            'I' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Get the highest row with data
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();

                // Apply alternating row colors for better readability
                for ($row = 2; $row <= $highestRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('F8F9FA'); // Light gray for even rows
                    }
                }

                // Format currency columns
                $sheet->getStyle('F2:F' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('"₱"#,##0.00');

                // Format quantity columns
                $sheet->getStyle('D2:D' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                // Auto-size columns for better fit
                foreach (range('A', $highestColumn) as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                // Set a minimum width for certain columns
                $sheet->getColumnDimension('B')->setWidth(20); // Wastage #
                $sheet->getColumnDimension('C')->setWidth(30); // Store
                $sheet->getColumnDimension('G')->setWidth(15); // Status

                // Add title row with company info
                $sheet->insertNewRowBefore(1, 2);
                $sheet->mergeCells('A1:I1');
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
                $sheet->mergeCells('A2:I2');
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
                $sheet->getStyle('A3:I3')->applyFromArray([
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
                $sheet->getStyle('A4:I' . $highestRow)->applyFromArray([
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
                    $totalItems = 0;
                    $totalCost = 0;

                    foreach ($this->data as $item) {
                        $totalQty += $item['total_qty'] ?? 0;
                        $totalItems += $item['items_count'] ?? 0;
                        $totalCost += $item['total_cost'] ?? 0;
                    }

                    // Add summary row
                    $sheet->setCellValue('C' . $summaryRow, 'TOTAL:');
                    $sheet->setCellValue('D' . $summaryRow, $totalQty);
                    $sheet->setCellValue('E' . $summaryRow, $totalItems);
                    $sheet->setCellValue('F' . $summaryRow, $totalCost);

                    // Style summary row
                    $sheet->getStyle('C' . $summaryRow . ':I' . $summaryRow)->applyFromArray([
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