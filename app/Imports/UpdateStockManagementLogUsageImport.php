<?php

namespace App\Imports;

use App\Models\CostCenter;
use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Traits\InventoryUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class UpdateStockManagementLogUsageImport implements ToCollection, WithHeadingRow, WithStartRow
{
    use InventoryUsage;
    protected $branch;
    protected $importedData;
    protected $costCenters;

    public function startRow(): int
    {
        return 2;
    }

    public function __construct($branch)
    {
        $this->branch = $branch;
        $this->costCenters = array_reverse(CostCenter::pluck('id', 'name')->toArray());
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
                    ]
                );


                Log::info('Log usage import', [
                    'row' => $row,
                    'test' => $this->costCenters[$row['cost_center']]
                ]);

                $product->used += $row['quantity'];
                $product->save();

                $data = [
                    'id' => $row['id'],
                    'store_branch_id' => $this->branch,
                    'cost_center_id' =>  $this->costCenters[$row['cost_center']],
                    'quantity' => $row['quantity'],
                    'transaction_date' => $row['transaction_date'],
                    'remarks' => $row['remarks'] ?? null

                ];
                $this->handleInventoryUsage($data);

                return $data;
            })
            ->values();
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
