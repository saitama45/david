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
            Log::info('Processing row ', [
                'raw_data' => $row[0],
            ]);
            if (!empty($row[0])) {
                if (!$this->menu) {
                    $this->menu = Menu::create([
                        'product_id' => $row[0]
                    ]);
                    // Log::info('Processing row ', [
                    //     'raw_data' => 'if not this menu',
                    // ]);
                } else {
                    // Log::info('Processing row ', [
                    //     'raw_data' => 'if this menu',
                    // ]);
                    if (trim($row[0]) != 'SAP CODE') {
                        $this->menu->product_inventories()->attach($row['0'], [
                            'quantity' => $row['2'],
                            'unit' => $row['3']
                        ]);
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
