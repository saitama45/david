<?php

namespace App\Exports;

use App\Http\Controllers\StockManagementController;
use App\Models\ProductInventory;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StockManagementUpdateExport implements FromCollection, WithHeadings, WithMapping
{
    use Exportable;

    public function collection()
    {
        return ProductInventory::get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'quantity' => 0,
                    'unit_cost' => 0,
                    'transaction_date' => now()->format('Y-m-d')
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
            'Unit Cost',
            'Transaction Date',
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
            $row['unit_cost'],
            $row['transaction_date'],
            ''
        ];
    }
}
