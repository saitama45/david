<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class QtyVarianceCostVarianceReportExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
            $item['store_name'],
            $item['item_code'],
            $item['item_description'],
            $item['uom'],
            number_format($item['cost'], 2),
            number_format($item['actual_inventory'], 2),
            number_format($item['theoretical_inventory'], 2),
            number_format($item['qty_variance'], 2),
            number_format($item['actual_cost'], 2),
            number_format($item['theoretical_cost'], 2),
            number_format($item['cost_variance'], 2),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Style the header row
        $sheet->getStyle('A1:K1')->applyFromArray([
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
        $sheet->getStyle('A1:K' . $sheet->getHighestRow())->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);
    }
}
