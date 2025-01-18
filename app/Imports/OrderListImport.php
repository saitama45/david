<?php

namespace App\Imports;

use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class OrderListImport implements ToCollection, WithHeadingRow
{
    protected $importedData;

    /**
     * @param Collection $collection
     */
    public function collection(Collection $collection)
    {
        $this->importedData = $collection
            ->filter(function ($row) {
                return $row['qty'] > 0;
            })
            ->map(function ($row, $key) {
                $product = ProductInventory::with('unit_of_measurement')->where('inventory_code', $row['item_code'])->first();
                $totalCost = $product->cost * $row['qty'];
                return [
                    'id' => $product->id,
                    'inventory_code' => $product->inventory_code,
                    'name' => $product->name,
                    'cost' => $product->cost,
                    'unit_of_measurement' => $product->unit_of_measurement?->name,
                    'total_cost' => $totalCost,
                    'quantity' => $row['qty']
                ];
            })
            ->values();
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
