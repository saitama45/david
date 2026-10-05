<?php

namespace App\Exports;

use App\Support\ReportNumber;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// WithStrictNullComparison: a zero is written as 0 (shown 0.0000), not left as an empty cell.
class QtyVarianceCostVarianceReportExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStrictNullComparison
{
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return [
            'MEC Scheduled Date',
            'Store Branch',
            'Item Code',
            'Item Description',
            'UoM',
            'Cost',
            'Actual Inventory',
            'Theoretical Inventory',
            'Qty Variance',
            'Actual Cost',
            'Theoretical Cost',
            'Cost Variance',
        ];
    }

    public function map($item): array
    {
        return [
            \Carbon\Carbon::parse($item['mec_date'])->format('M j, Y'),
            $item['store_name'],
            $item['item_code'],
            $item['item_description'],
            $item['uom'],
            // Real numbers, so the sheet can be summed; columnFormats() shows them with four decimals.
            (float) $item['cost'],
            (float) $item['actual_inventory'],
            (float) $item['theoretical_inventory'],
            (float) $item['qty_variance'],
            (float) $item['actual_cost'],
            (float) $item['theoretical_cost'],
            (float) $item['cost_variance'],
        ];
    }

    /** Cost to Cost Variance: four decimals, as every report prints its numbers. */
    public function columnFormats(): array
    {
        return array_fill_keys(['F', 'G', 'H', 'I', 'J', 'K', 'L'], ReportNumber::EXCEL);
    }

    public function styles(Worksheet $sheet)
    {
        // Style the header row
        $sheet->getStyle('A1:L1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF4F81BD'], // Dark Blue
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);

        // Apply border to all data cells
        $sheet->getStyle('A1:L' . $sheet->getHighestRow())->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);
    }
}
