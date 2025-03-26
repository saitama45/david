<?php

namespace App\Imports;

use App\Models\CostCenter;
use App\Models\ProductInventoryStock;
use App\Traits\InventoryUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Validators\Failure;

class UpdateStockManagementLogUsageImport implements ToCollection, WithHeadingRow
{
    use InventoryUsage;

    protected $branch;
    protected $importedData = [];
    protected $errors = [];

    public function __construct($branch)
    {
        $this->branch = $branch;
    }

    public function collection(Collection $collection)
    {
        // Check if the collection is empty
        if ($collection->isEmpty()) {
            $this->errors[] = "The uploaded file is empty.";
            return;
        }

        // Get the first row's headings
        $headings = $collection->first()->keys()->map(function ($heading) {
            return strtolower(trim($heading));
        })->toArray();

        // More robust heading check
        $missingColumns = [];
        if (!in_array('id', $headings)) {
            $missingColumns[] = 'id';
        }
        if (!in_array('cost_center', $headings)) {
            $missingColumns[] = 'cost_center';
        }
        if (!in_array('quantity', $headings)) {
            $missingColumns[] = 'quantity';
        }

        // If any required columns are missing, stop the import
        if (!empty($missingColumns)) {
            $this->errors[] = "Missing required columns: " . implode(', ', $missingColumns);
            $this->errors[] = "Existing columns: " . implode(', ', $headings);
            return;
        }

        $costCenters = CostCenter::pluck('id', 'name')->toArray();

        foreach ($collection as $index => $row) {
            try {
                if (empty($row['id']) || empty($row['quantity'])) {
                    continue;
                }
                // Create or update product inventory stock
                $product = ProductInventoryStock::firstOrCreate(
                    [
                        'product_inventory_id' => $row['id'],
                        'store_branch_id' => $this->branch
                    ],
                    ['quantity' => 0]
                );

                // Update used quantity
                $product->used = ($product->used ?? 0) + $row['quantity'];
                $product->save();

                // Prepare data for inventory usage
                $data = [
                    'id' => $row['id'],
                    'store_branch_id' => $this->branch,
                    'cost_center_id' => $costCenters[$row['cost_center']],
                    'quantity' => $row['quantity'],
                    'transaction_date' => $row['transaction_date'] ?? now(),
                    'remarks' => $row['remarks'] ?? null
                ];

                // Handle inventory usage
                $this->handleInventoryUsage($data);

                // Store successfully imported data
                $this->importedData[] = $data;
            } catch (\Exception $e) {
                // Log any unexpected errors
                Log::error('Import error in row', [
                    'error' => $e->getMessage(),
                    'row' => $row->toArray()
                ]);
                $this->errors[] = "Unexpected error in row " . ($index + 2) . ": " . $e->getMessage();
            }
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
