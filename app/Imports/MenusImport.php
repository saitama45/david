<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class MenusImport implements ToCollection, WithStartRow
{
    private ?Menu $menu;

    public function __construct()
    {
        $this->menu = null;
    }
    public function startRow(): int
    {
        return 1;
    }
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (!empty($row[0])) {
                if (!$this->menu) {
                    $this->menu = Menu::updateOrCreate([
                        'product_id' => $row[0]
                    ], [
                        'name' => $row[1]
                    ]);
                    // Log::info('Processing row ', [
                    //     'raw_data' => 'if not this menu',
                    // ]);
                } else {
                    // Log::info('Processing row ', [
                    //     'raw_data' => 'if this menu',
                    // ]);
                    if (trim($row['0']) != 'SAP CODE') {
                        $item = ProductInventory::where('inventory_code', $row['0'])->first();
                        if ($item) {
                            $this->menu->product_inventories()->attach($item->id, [
                                'quantity' => $row['2'],
                                'unit' => $row['3']
                            ]);

                            Log::info('Processing row ', [
                                'raw_data' => $row['0'],
                                'item' => $item
                            ]);
                        }
                    }
                }
            } else {

                // Log::info('Processing row ', [
                //     'raw_data' => 'else',
                // ]);
                $this->menu = null;
            }
        }
    }
}
