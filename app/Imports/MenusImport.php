<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class MenusImport implements ToCollection, WithHeadingRow, WithStartRow
{
    private Menu $menu;
    public function startRow(): int
    {
        return 1;
    }
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            if (!empty($row[0])) {
                if (!$this->menu) {
                    $this->menu = Menu::create([
                        'product_id' => $row[0]
                    ]);
                } else {
                    if (trim($row[0]) != 'SAP CODE') {
                        $this->menu->product_inventories()->attach($row['0'], [
                            'quantity' => $row['2'],
                            'unit' => $row['3']
                        ]);
                    }
                }
            } else {
                unset($this->menu);
            }
        }
    }
}
