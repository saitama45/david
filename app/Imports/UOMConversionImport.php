<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\UnitOfMesurementConversion;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UOMConversionImport implements ToModel, WithHeadingRow
{
    /**
     * @param Collection $collection
     */

    public function model(array $row)
    {
        if (!isset($row['item_no'])) return null;
        $product = ProductInventory::where('inventory_code', $row['item_no'])->first();
        if (!$product) {
            return null;
        }
        dd($row);
        return new UnitOfMesurementConversion([
            'inventory_code' => $row['item_no'],
            'uom_group' => $row['uom_group'],
            'alternative_quantity' => $row['altqty'],
            'base_quantity' => $row['baseqty'],
            'alternative_uom' => $row['altuom'],
            'base_uom' => $row['baseuom'],
        ]);
    }
}
