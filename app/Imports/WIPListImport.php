<?php

namespace App\Imports;

use App\Models\WIP;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class WIPListImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        if (!$row['sap_code']) return;
        return WIP::updateOrCreate(
            ['sap_code' => $row['sap_code']],
            [
                'sap_code' => $row['sap_code'],
                'name' => $row['name'],
                'remarks' => $row['remarks']
            ]
        );
    }
}
