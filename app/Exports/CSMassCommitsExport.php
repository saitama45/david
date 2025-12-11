<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CSMassCommitsExport implements FromCollection, WithHeadings, WithStyles, WithStrictNullComparison
{
    protected $reportData;
    protected $headers;
    protected $totalBranches;
    protected $orderDate;

    public function __construct($reportData, $headers, $totalBranches, $orderDate)
    {
        $this->reportData = $reportData;
        $this->headers = $headers;
        $this->totalBranches = $totalBranches;
        $this->orderDate = $orderDate;
    }

    public function collection()
    {
        // Define fields that are text-based and should not default to 0
        $textFields = ['category', 'classification', 'item_code', 'item_name', 'unit', 'whse', 'remarks'];

        // Map each report item to an ordered array based on the header fields
        return collect($this->reportData)->map(function ($row) use ($textFields) {
            $orderedRow = [];
            foreach ($this->headers as $header) {
                $field = $header['field'];
                $value = $row[$field] ?? null;

                // If it's not a text field (i.e., it's a quantity column)
                if (!in_array($field, $textFields)) {
                    // Force any falsy value (0, 0.0, null, '') to integer 0
                    if (!$value) {
                        $value = 0;
                    }
                }

                $orderedRow[] = $value;
            }
            return $orderedRow;
        });
    }

    public function headings(): array
    {
        // This will create a multi-row heading
        return [
            ['CS Mass Commits Report for ' . $this->orderDate],
            collect($this->headers)->pluck('label')->toArray()
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Style the first row (title) to be bold and merged
        $sheet->mergeCells('A1:' . $sheet->getHighestColumn() . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Style the second row (headers) to be bold
        $sheet->getStyle('A2:' . $sheet->getHighestColumn() . '2')->getFont()->setBold(true);
        $sheet->getStyle('A2:' . $sheet->getHighestColumn() . '2')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF92D050'); // A shade of green

        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return [];
    }
}
