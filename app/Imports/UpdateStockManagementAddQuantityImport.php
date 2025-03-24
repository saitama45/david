<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class UpdateStockManagementAddQuantityImport implements ToCollection, WithHeadingRow, WithStartRow
{
    protected $branch;
    protected $importedData;

    public function startRow(): int
    {
        return 2;
    }

    public function __construct($branch)
    {
        $this->branch = $branch;
    }

    public function collection(Collection $collection)
    {
        $this->importedData = $collection
            ->filter(function ($row) {
                return !empty($row['quantity']) && $row['quantity'] > 0;
            })
            ->map(function ($row) {
                $product = ProductInventoryStock::firstOrCreate(
                    [
                        'product_inventory_id' => $row['id'],
                        'store_branch_id' => $this->branch
                    ],
                    [
                        'quantity' => 0,
                        'unit_cost' => $row['unit_cost']
                    ]
                );

                $batch = PurchaseItemBatch::create([
                    'product_inventory_id' => $row['id'],
                    'purchase_date' => $row['transaction_date'],
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'remaining_quantity' => $row['quantity']
                ]);

                $product->quantity += $row['quantity'];
                $product->recently_added = $row['quantity'];
                $product->save();

                $stockManager = new ProductInventoryStockManager([
                    'product_inventory_id' => $row['id'],
                    'store_branch_id' => $this->branch,
                    'quantity' => $row['quantity'],
                    'action' => 'add_quantity',
                    'transaction_date' => $row['transaction_date'],
                    'unit_cost' => $row['unit_cost'],
                    'total_cost' => $row['unit_cost'] * $row['quantity'],
                    'remarks' => $row['remarks'] ?? null
                ]);

                $batch->product_inventory_stock_managers()->save($stockManager);

                return [
                    'product_id' => $row['id'],
                    'product_name' => ProductInventory::find($row['id'])->name ?? 'Unknown',
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'total_cost' => $row['unit_cost'] * $row['quantity'],
                    'transaction_date' => $row['transaction_date']
                ];
            })
            ->values();
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
