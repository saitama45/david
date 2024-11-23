<?php

namespace App\Imports;

use Illuminate\Support\Collection;
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
                return [
                    'category' => $row[0],
                    'classification' => $row[1],
                    'item_code' => $row[2],
                    'item_name' => $row[3],
                    'package_configuration' => $row[4],
                    'unit' => $row[5],
                    'quantity' => $row[6],
                    'cost' => 0
                ];
            })
            ->values();
    }

    public function getImportedData()
    {
        return $this->importedData;
    }

    
}
