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
            // 'ID',
            'Item Code',
            'Item Name',
            'Area',
            'Category 2',
            'Category',
            'Brand',
            'Packaging Config',
            'Config',
            'UOM',
            // 'Created By',
            // 'Updated By',
            // 'Created At',
            // 'Updated At'
        ];
    }

    public function map($template): array
    {
        return [
            // $template->id,
            $template->item_code,
            $template->item_name,
            $template->area,
            $template->category_2,
            $template->category,
            $template->brand,
            $template->packaging_config,
            $template->config,
            $template->uom,
            // $template->createdBy->name ?? 'N/A',
            // $template->updatedBy->name ?? 'N/A',
            // $template->created_at->format('Y-m-d H:i:s'),
            // $template->updated_at->format('Y-m-d H:i:s')
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