<?php

namespace App\Imports;

use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Maatwebsite\Excel\Concerns\ToCollection;

class OrderListImport implements ToCollection
{
    protected $importedData;

    /**
     * @param Collection $collection
     */
    public function collection(Collection $collection)
    {
        $this->importedData = $collection->skip(1)
            ->filter(function ($row) {
                return $row[6] > 0;
            })
            ->map(function ($row, $key) {
                $product = ProductInventory::with('unit_of_measurement')->where('inventory_code', $row[2])->first();
                $totalCost = $product->cost * $row[6];
                return [
                    'id' => $product->id,
                    'inventory_code' => $product->inventory_code,
                    'name' => $product->name,
                    'cost' => $product->cost,
                    'unit_of_measurement' => $product->unit_of_measurement?->name,
                    'total_cost' => $totalCost,
                    'quantity' => $row[6]
                ];
            })
            ->values();
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
