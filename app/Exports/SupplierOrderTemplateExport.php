<?php

namespace App\Exports;

use App\Models\SupplierItems;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles; // Import WithStyles
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet; // Import Worksheet
use PhpOffice\PhpSpreadsheet\Style\Fill; // Import Fill

class SupplierOrderTemplateExport implements FromCollection, WithHeadings, WithMapping, WithStyles // Add WithStyles
{
    protected $supplierCode;

    public function __construct(string $supplierCode)
    {
        $this->supplierCode = $supplierCode;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        // Fetch SupplierItems for the given supplier code
        return SupplierItems::where('SupplierCode', $this->supplierCode)
                            ->where('is_active', true)
                            ->get();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Category',
            'Brand',
            'Classification',
            'Item Code',
            'Item Name',
            'Packaging Config',
            'Unit',
            'Cost',
            'SRP',
            'Supplier Code',
            'ACTIVE',
            'Qty', // Added Qty column
        ];
    }

    /**
     * @param mixed $item
     * @return array
     */
    public function map($item): array
    {
        // Map the SupplierItem properties to the desired Excel columns
        return [
            $item->category,
            $item->brand,
            $item->classification,
            $item->ItemCode,
            $item->item_name,
            $item->packaging_config,
            $item->uom,
            $item->cost,
            $item->srp,
            $item->SupplierCode,
            $item->is_active ? 'Yes' : 'No',
            '', // Empty Qty column for user input
        ];
    }

    /**
     * Apply styles to the worksheet.
     *
     * @param Worksheet $sheet
     */
    public function styles(Worksheet $sheet)
    {
        // Apply style to the first row (headers)
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '00008B', // Dark Blue
                ],
            ],
            'font' => [
                'bold' => true,
                'color' => [
                    'rgb' => 'FFFFFF', // White text
                ],
            ],
        ]);
    }
}
