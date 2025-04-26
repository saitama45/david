<?php

namespace App\Exports;

use App\Models\ProductInventory;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StockMangementSOHExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return ProductInventory::get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'quantity' => 0,
                ];
            });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Product Name',
            'Inventory Code',
            'Quantity',
            'Remarks'
        ];
    }

    public function map($row): array
    {
        return [
            $row['id'],
            $row['name'],
            $row['inventory_code'],
            $row['quantity'],
            ''
        ];
    }
}
