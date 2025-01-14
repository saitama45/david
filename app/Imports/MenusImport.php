<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MenusImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            $menu = Menu::create([
                'category_id' => ['category_id'],
                'name' => $row['name'],
                'price' => $row['price'],
                'remarks' => $row['remarks'] ?? null,
            ]);

            $ingredientsList = explode(',', $row['ingredients']);

            foreach ($ingredientsList as $ingredientInfo) {
                $ingredientInfo = trim($ingredientInfo);

                list($ingredientCode, $quantity, $unit) = array_pad(
                    explode(':', $ingredientInfo),
                    3,
                    null
                );

                $ingredientCode = trim($ingredientCode);
                $quantity = trim($quantity);
                $unit = trim($unit);


                $ingredient = ProductInventory::select('id')->where('inventory_code', $ingredientCode)->first();

                $menu->ingredients()->attach($ingredient->id, [
                    'quantity' => $quantity,
                    'unit' => $unit,
                ]);
            }
        }
    }
}
