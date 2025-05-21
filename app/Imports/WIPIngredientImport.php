<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\WIP;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class WIPIngredientImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        // Check if column sap cde is empty
        if (!$row['sap_code']) return;
        // Check if there is an exisiting wip
        $sap_code = trim($row['sap_code']);
        $inventory_code = trim($row['inventory_code']);
        $wip =  WIP::firstOrCreate(
            ['sap_code' => $sap_code],
            [
                'sap_code' => $sap_code,
                'name' => $row['name']
            ]
        );
        // Get the ingredient id 
        $product = ProductInventory::select(['id'])->where('inventory_code', $inventory_code)->first();

        if (!$product) {
            return back()->withErrors([
                'message' => 'Please make sure that your inventory is updated before proceeding'
            ]);
        }

        // Create data
        return $wip->wip_ingredients()->updateOrCreate(
            ['product_inventory_id' => $product->id,],
            [
                'product_inventory_id' => $product->id,
                'quantity' => $row['qty'],
                'unit' => $row['uom']
            ]
        );
    }
}
