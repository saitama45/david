<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\ProductInventoryStockManager;
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

        foreach ($collection as $index => $row) {
            if ($row['quantity'] == false) continue;
            ProductInventoryStockManager::create([
                'product_inventory_id' => $row['id'],
                'store_branch_id' => $this->branch,
                'quantity' => $row['quantity'],
                'action' => 'soh_adjustment',
                'unit_cost' => 0,
                'total_cost' => 0,
                'transaction_date' => $row['transaction_date'] ?? now(),
                'is_stock_adjustment' => true,
                'remarks' => $row['remarks'] ?? null,
            ]);
        }
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
