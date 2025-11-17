<?php

namespace App\Exports;

use App\Models\MonthEndCountTemplate;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class MonthEndCountTemplatesExport implements FromQuery, WithHeadings, WithMapping, WithTitle, WithStyles
{
    protected $search;

    public function __construct($search = null)
    {
        $this->search = $search;
    }

    public function query()
    {
        return MonthEndCountTemplate::query()
            ->with(['createdBy', 'updatedBy'])
            ->search($this->search);
    }

    public function title(): string
    {
        return 'Month End Count Templates';
    }

    public function headings(): array
    {
        return [
            'Item Code',
            'Item Name',
            'Category 1',
            'Area',
            'Category 2',
            'Packaging',
            'Conversion',
            'Bulk UOM',
            'Loose UOM',
        ];
    }

    public function map($template): array
    {
        return [
            $template->item_code,
            $template->item_name,
            $template->category,
            $template->area,
            $template->category_2,
            $template->packaging_config,
            $template->config,
            $template->uom,
            $template->loose_uom,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Apply header styling (sky blue background, bold text, centered)
        $sheet->getStyle('A1:I1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '87CEEB', // Sky blue background
                ],
            ],
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }
}