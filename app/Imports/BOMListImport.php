<?php

namespace App\Imports;

use App\Models\Menu;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BOMListImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        if (!$row['sap_code']) return;
        return Menu::updateOrCreate(
            ['product_id' => $row['sap_code']],
            [
                'product_id' => $row['sap_code'],
                'name' => $row['name'],
                'remarks' => $row['remarks']
            ]
        );
    }
}
