<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class MonthEndCountTemplateExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths
{
    protected $items;

    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->items;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'ItemCode',
            'item_name',
            'area',
            'category2',
            'category',
            'brand',
            'packaging_config',
            'config',
            'uom',
            'current_soh',
            'bulk_qty',
            'loose_qty',
            'loose_uom',
            'remarks',
            'total_qty',
        ];
    }

    /**
     * Apply styles to highlight fillable columns
     */
    public function styles(Worksheet $sheet)
    {
        $lastRow = $this->items->count() + 1; // +1 for header row

        // Fillable columns: H (config), K (bulk_qty), L (loose_qty), M (loose_uom), N (remarks), O (total_qty)
        $fillableColumns = ['H', 'K', 'L', 'M', 'N', 'O'];

        foreach ($fillableColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFE599'); // Light yellow/orange color
        }

        // Style the header row
        $sheet->getStyle('A1:O1')->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFD9D9D9'], // Light gray
            ],
        ]);

        return [];
    }

    /**
     * Set column widths for better readability
     */
    public function columnWidths(): array
    {
        return [
            'A' => 12,  // ItemCode
            'B' => 30,  // item_name
            'C' => 15,  // area
            'D' => 15,  // category2
            'E' => 15,  // category
            'F' => 15,  // brand
            'G' => 18,  // packaging_config
            'H' => 15,  // config
            'I' => 12,  // uom
            'J' => 12,  // current_soh
            'K' => 12,  // bulk_qty
            'L' => 12,  // loose_qty
            'M' => 12,  // loose_uom
            'N' => 25,  // remarks
            'O' => 12,  // total_qty
        ];
    }
}