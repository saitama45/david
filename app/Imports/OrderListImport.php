<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\UnitOfMesurementConversion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
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

                // row unit can have '()' 
                // if it has '()' get the UOM before it and get the quantity inside '()'

                $product = ProductInventory::with('unit_of_measurement')->where('inventory_code', $row['item_code'])->first();
                if (!$product) return null;


                $quantity = null;
                if (str_contains($row['unit'], '(')) {
                    $unit = $row['unit'];
                    $start = strpos($unit, '(') + 1;
                    $end = strpos($unit, ')');

                    $uom = substr($row['unit'], 0, $start - 1);
                    $conversion = substr($unit, $start, $end - $start);

                    $quantity = floatval($conversion) * $row['qty'];
                }


                $totalCost = $product->cost * ($quantity ??  $row['qty']);

                return [
                    'id' => $product->id,
                    'inventory_code' => $product->inventory_code,
                    'name' => $product->name,
                    'cost' => $product->cost,
                    'unit_of_measurement' => $product->unit_of_measurement?->name,
                    'total_cost' => $totalCost,
                    'quantity' => $quantity ?? $row['qty'],
                    'uom' => $row['unit']
                ];
            })
            ->values();
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
