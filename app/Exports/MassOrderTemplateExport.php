<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class MassOrderTemplateExport implements FromCollection, WithHeadings, ShouldAutoSize, WithStyles
{
    protected $items;
    protected $staticHeaders;
    protected $dynamicHeaders;
    protected $supplierCode;

    public function __construct($items, $staticHeaders, $dynamicHeaders, $supplierCode)
    {
        $this->items = $items;
        $this->staticHeaders = $staticHeaders;
        $this->dynamicHeaders = $dynamicHeaders;
        $this->supplierCode = $supplierCode;
    }

    public function collection()
    {
        $sortedItems = $this->items->sortBy(function ($item) {
            return strtolower($item->category ?? '');
        })->values();

        return $sortedItems->map(function ($item) {
            $row = [];
            $row['Ordering Template'] = $this->supplierCode;
            $row['Category'] = $item->category;
            $row['Classification'] = $item->classification;
            $row['Item Code'] = $item->ItemCode;
            $row['Item Name'] = $item->item_name;
            $row['Packaging Config'] = $item->packaging_config;
            $row['Unit'] = $item->uom;

            foreach ($this->dynamicHeaders as $header) {
                $row[$header] = '';
            }
            return $row;
        });
    }

    public function headings(): array
    {
        return array_merge(['Ordering Template'], $this->staticHeaders, $this->dynamicHeaders);
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '00008B'], // Dark Blue
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'], // White text
            ],
        ]);
    }
}
