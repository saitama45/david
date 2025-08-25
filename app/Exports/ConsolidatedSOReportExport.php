<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate; // CRITICAL FIX: Import Coordinate class

class ConsolidatedSOReportExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    protected $reportData;
    protected $dynamicHeaders;
    protected $totalBranches;

    public function __construct(Collection $reportData, array $dynamicHeaders, int $totalBranches)
    {
        $this->reportData = $reportData;
        $this->dynamicHeaders = $dynamicHeaders;
        $this->totalBranches = $totalBranches;
    }

    public function collection()
    {
        return $this->reportData;
    }

    public function headings(): array
    {
        $headings = [];
        foreach ($this->dynamicHeaders as $header) {
            $label = $header['label'];
            // CRITICAL FIX: Remove ' Qty' from dynamic branch headers
            if (str_ends_with($label, ' Qty')) {
                $headings[] = str_replace(' Qty', '', $label);
            } else {
                $headings[] = $label;
            }
        }
        return $headings;
    }

    public function map($row): array
    {
        $mappedRow = [];
        foreach ($this->dynamicHeaders as $header) {
            $field = $header['field'];
            $value = $row[$field] ?? '';

            // Format quantities to 2 decimal places, others as is
            if (str_contains($header['label'], 'Qty') || $field === 'total_quantity') {
                $mappedRow[] = number_format((float)$value, 2, '.', '');
            } else {
                $mappedRow[] = $value;
            }
        }
        return $mappedRow;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastColumnLetter = $sheet->getHighestColumn(); // Get the letter (e.g., 'Z', 'AA')

                // CRITICAL FIX: Convert column letter to numeric index for iteration
                $lastColumnIndex = Coordinate::columnIndexFromString($lastColumnLetter);

                // Style for headers
                $sheet->getStyle('A1:' . $lastColumnLetter . '1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '4CAF50'], // Green background
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                // Style for data rows
                $sheet->getStyle('A2:' . $lastColumnLetter . $lastRow)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'DDDDDD'],
                        ],
                    ],
                ]);

                // CRITICAL FIX: Auto size columns by iterating numerically
                for ($col = 1; $col <= $lastColumnIndex; $col++) {
                    $columnLetter = Coordinate::stringFromColumnIndex($col);
                    $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
                }
            },
        ];
    }
}
