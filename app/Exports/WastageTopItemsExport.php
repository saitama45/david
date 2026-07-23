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
use Carbon\Carbon;

class WastageTopItemsExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithEvents
{
    protected $data;
    protected $meta;

    public function __construct($data, array $meta = [])
    {
        $this->data = $data;
        $this->meta = $meta;
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
            'Month',
            'Rank',
            'Item Code',
            'Item Description',
            'UoM',
            'Total Qty',
            'Total Amount',
            '% of Month',
            'Records',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,    // #
            'B' => 18,   // Month
            'C' => 8,    // Rank
            'D' => 15,   // Item Code
            'E' => 45,   // Item Description
            'F' => 10,   // UoM
            'G' => 14,   // Total Qty
            'H' => 18,   // Total Amount
            'I' => 12,   // % of Month
            'J' => 10,   // Records
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            'A' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'C' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'F' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'G' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
            'H' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
            'I' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]],
            'J' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestColumn = 'J';

                // Push the data down to make room for the report title block
                $sheet->insertNewRowBefore(1, 2);

                $highestRow = $sheet->getHighestRow();
                $headerRow = 3;
                $firstDataRow = 4;

                // Title
                $sheet->mergeCells('A1:' . $highestColumn . '1');
                $sheet->setCellValue('A1', 'TOP WASTE ITEMS PER MONTH');
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

                // Sub title: coverage + generation date
                $sheet->mergeCells('A2:' . $highestColumn . '2');
                $sheet->setCellValue('A2', $this->subtitle());
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

                // Heading row
                $sheet->getStyle('A' . $headerRow . ':' . $highestColumn . $headerRow)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 12,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '4472C4'],
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

                if ($highestRow < $firstDataRow) {
                    return;
                }

                // Data borders + zebra striping
                $sheet->getStyle('A' . $firstDataRow . ':' . $highestColumn . $highestRow)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'E0E0E0'],
                        ],
                    ],
                ]);

                for ($row = $firstDataRow; $row <= $highestRow; $row++) {
                    if ($row % 2 == 0) {
                        $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)
                            ->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()
                            ->setRGB('F8F9FA');
                    }
                }

                // Number formats
                $sheet->getStyle('G' . $firstDataRow . ':G' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.000');

                $sheet->getStyle('H' . $firstDataRow . ':H' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('"₱"#,##0.00');

                $sheet->getStyle('I' . $firstDataRow . ':I' . $highestRow)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00"%"');

                // Grand total row
                $summaryRow = $highestRow + 2;
                $totalQty = 0;
                $totalAmount = 0;

                foreach ($this->data as $item) {
                    $totalQty += $item['Total Qty'] ?? 0;
                    $totalAmount += $item['Total Amount'] ?? 0;
                }

                $sheet->setCellValue('F' . $summaryRow, 'TOTAL:');
                $sheet->setCellValue('G' . $summaryRow, $totalQty);
                $sheet->setCellValue('H' . $summaryRow, $totalAmount);

                $sheet->getStyle('G' . $summaryRow)->getNumberFormat()->setFormatCode('#,##0.000');
                $sheet->getStyle('H' . $summaryRow)->getNumberFormat()->setFormatCode('"₱"#,##0.00');

                $sheet->getStyle('F' . $summaryRow . ':' . $highestColumn . $summaryRow)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 12,
                        'color' => ['rgb' => '2C3E50'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E8F4FD'],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);
            },
        ];
    }

    private function subtitle(): string
    {
        $parts = [];

        if (!empty($this->meta['date_from']) && !empty($this->meta['date_to'])) {
            $parts[] = 'Coverage: '
                . Carbon::parse($this->meta['date_from'])->format('M d, Y')
                . ' - '
                . Carbon::parse($this->meta['date_to'])->format('M d, Y');
        }

        $topLimit = $this->meta['top_limit'] ?? null;
        $parts[] = empty($topLimit) ? 'All items per month' : ('Top ' . $topLimit . ' items per month');
        $parts[] = 'Generated on: ' . now()->format('F d, Y - h:i A');

        return implode('  |  ', $parts);
    }
}
