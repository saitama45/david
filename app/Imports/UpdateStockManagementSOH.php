<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Validators\Failure;

class UpdateStockManagementSOH implements ToCollection, WithHeadingRow
{
    /**
    * @param Collection $collection
    */

    protected $branch;
    protected $importedData = [];
    protected $errors = [];

    public function __construct($branch)
    {
        $this->branch = $branch;
    }
    public function collection(Collection $collection)
    {
        //
    }

    public function getImportedData()
    {
        return $this->importedData;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $this->errors[] = "Import failure: " . implode(', ', $failure->errors());
        }
    }
}
