<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CSMassCommitsExport implements FromCollection, WithHeadings, WithStyles
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
        return $this->reportData;
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

        return [];
    }
}
