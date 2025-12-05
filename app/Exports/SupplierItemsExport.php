<?php

namespace App\Exports;

use App\Models\SupplierItems;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SupplierItemsExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $search;
    protected $filter;
    protected $assignedSupplierCodes; // New property to hold assigned supplier codes

    public function __construct($search = null, $filter = null, array $assignedSupplierCodes = [])
    {
        $this->search = $search;
        $this->filter = $filter;
        $this->assignedSupplierCodes = $assignedSupplierCodes; // Initialize the new property
    }

    public function query()
    {
        $query = SupplierItems::query()
            // Filter SupplierItems to only include those assigned to the current user
            ->whereIn('SupplierCode', $this->assignedSupplierCodes);

        // Apply search logic
        if ($this->search) {
            $query->where(function ($q) {
                // Ensure these columns exist in your SupplierItems model/table
                $q->where('ItemCode', 'like', '%' . $this->search . '%')
                  ->orWhere('item_name', 'like', '%' . $this->search . '%') // Re-included item_name
                  ->orWhere('SupplierCode', 'like', '%' . $this->search . '%')
                  ->orWhere('category', 'like', '%' . $this->search . '%')
                  ->orWhere('brand', 'like', '%' . $this->search . '%')
                  ->orWhere('classification', 'like', '%' . $this->search . '%')
                  ->orWhere('packaging_config', 'like', '%' . $this->search . '%')
                  ->orWhere('uom', 'like', '%' . $this->search . '%');
            });
        }

        // Apply filter logic
        if ($this->filter && $this->filter !== 'all') {
            if ($this->filter === 'is_active') {
                $query->where('is_active', true);
            } elseif ($this->filter === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return $query;
    }

    public function headings(): array
    {
        // Define your exact column headers for the Excel file
        return [
            'Category',
            'Category 2',
            'Area',
            'Brand',
            'Classification',
            'Item Code',
            'Item Name', // Re-included item_name
            'Packaging Config',
            'Unit',
            'Cost',
            'SRP', // Re-included SRP
            'Supplier Code',
            'Sort Order',
            'ACTIVE',
        ];
    }

    /**
     * @param mixed $item
     * @return array
     */
    public function map($item): array
    {
        // Map the model attributes to the array that will be a row in Excel
        // Ensure these attributes exist on your SupplierItems model
        return [
            $item->category,
            $item->category2,
            $item->area,
            $item->brand,
            $item->classification,
            $item->ItemCode,
            $item->item_name, // Re-included item_name
            $item->packaging_config,
            $item->uom,
            $item->cost,
            $item->srp,
            $item->SupplierCode,
            $item->sort_order,
            (int) $item->is_active,
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
