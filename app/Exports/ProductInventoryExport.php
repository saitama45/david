<?php

namespace App\Exports;

use App\Models\ProductInventory;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductInventoryExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $search;
    protected $filter;
    public function __construct($search = null, $filter = null)
    {
        $this->search = $search;
        $this->filter = $filter;
    }

    public function query()
    {
        $query = ProductInventory::query()->with(['inventory_category', 'unit_of_measurement', 'product_categories']);

        if ($this->filter === 'with_cost') {
            $query->where('cost', '>', 0.0);
        }

        if ($this->filter === 'without_cost') {
            $query->whereRaw('ISNULL(cost, 0) = 0');
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('inventory_code', 'like', "%{$this->search}%");
            });
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Status',
            'Barcode',
            'Inventory Name',
            'Inventory ID',
            'Brand',
            'Category - A',
            'Category - B',
            'Conversion',
            'UOM',
            'COST',
            'Inventory Category'
        ];
    }

    public function map($row): array
    {
        return [
            $row->is_active ? 'Active' : 'Inactive',
            $row->barcode,
            $row->name,
            $row->inventory_code,
            $row->brand,
            'N/a',
            'N/a',
            $row->conversion,
            $row->unit_of_measurement->name,
            $row->cost,
            $row->inventory_category->name
        ];
    }
}
