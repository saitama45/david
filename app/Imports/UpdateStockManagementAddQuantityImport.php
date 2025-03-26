<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Validators\Failure;

class UpdateStockManagementAddQuantityImport implements ToCollection, WithHeadingRow
{
    protected $branch;
    protected $importedData = [];
    protected $errors = [];

    public function __construct($branch)
    {
        $this->branch = $branch;
    }

    public function collection(Collection $collection)
    {
        // Check if required headings are present
        $headings = $collection->first()->keys()->toArray();

        if (!in_array('id', $headings) || in_array('cost_center', $headings)) {
            $this->errors[] = $headings;
            return;
        }

        foreach ($collection as $index => $row) {
            try {
                if (empty($row['id']) || empty($row['quantity'])) {
                    continue;
                }
                // Verify product exists and data is valid
                $product = ProductInventory::find($row['id']);
                // if (!$product) {
                //     $this->errors[] = "Row " . ($index + 2) . ": Product ID {$row['id']} does not exist";
                //     continue;
                // }

                // Skip rows with empty or invalid data


                // Use database transaction for data integrity
                DB::beginTransaction();

                // Create or update product inventory stock
                $inventoryStock = ProductInventoryStock::firstOrCreate(
                    [
                        'product_inventory_id' => $row['id'],
                        'store_branch_id' => $this->branch
                    ],
                    [
                        'quantity' => 0,
                        'unit_cost' => $row['unit_cost']
                    ]
                );

                // Create purchase item batch
                $batch = PurchaseItemBatch::create([
                    'product_inventory_id' => $row['id'],
                    'purchase_date' => $row['transaction_date'] ?? now(),
                    'store_branch_id' => $this->branch,
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'remaining_quantity' => $row['quantity']
                ]);

                // Update inventory stock
                $inventoryStock->quantity += $row['quantity'];
                $inventoryStock->recently_added = $row['quantity'];
                $inventoryStock->save();

                // Create stock manager record
                $stockManager = new ProductInventoryStockManager([
                    'product_inventory_id' => $row['id'],
                    'store_branch_id' => $this->branch,
                    'quantity' => $row['quantity'],
                    'action' => 'add_quantity',
                    'transaction_date' => $row['transaction_date'] ?? now(),
                    'unit_cost' => $row['unit_cost'],
                    'total_cost' => $row['unit_cost'] * $row['quantity'],
                    'remarks' => $row['remarks'] ?? null
                ]);

                $batch->product_inventory_stock_managers()->save($stockManager);

                // Commit transaction
                DB::commit();

                // Store successfully imported data
                $this->importedData[] = [
                    'product_id' => $row['id'],
                    'product_name' => $product->name,
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'total_cost' => $row['unit_cost'] * $row['quantity'],
                    'transaction_date' => $row['transaction_date'] ?? now()
                ];
            } catch (\Exception $e) {
                // Rollback transaction on error
                DB::rollBack();

                // Log and record the error
                Log::error('Import error in row', [
                    'error' => $e->getMessage(),
                    'row' => $row->toArray()
                ]);
                $this->errors[] = "Unexpected error: " . $e->getMessage();
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
