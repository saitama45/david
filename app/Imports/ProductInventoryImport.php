<?php

namespace App\Imports;

use App\Models\InventoryCategory;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ProductInventoryImport implements ToModel, WithHeadingRow, WithStartRow, WithValidation
{
    use Importable;
    private $productCategories;
    private $unitOfMeasurements;
    private $inventoryCategories;
    private $statuses;
    private $errors;
    private $inventoryCategoryId;
    private $rowNumber = 0;
    private $maxEmptyRows = 10;

    public function startRow(): int
    {
        return 6;
    }

    public function headingRow(): int
    {
        return 5;
    }

    public function __construct()
    {
        $this->statuses = [
            'Active' => true,
            'Inactive' => true
        ];
        $this->unitOfMeasurements = array_flip(UnitOfMeasurement::options()->toArray());
        $this->inventoryCategories = array_flip(InventoryCategory::options()->toArray());
        $this->errors = collect();
    }

    public function model(array $row)
    {
        $this->rowNumber++;
        Log::info('Processing row ' . $this->rowNumber, [
            'raw_data' => $row,
            'headers_found' => array_keys($row)
        ]);


        if (empty($row['inventory_id']) && empty($row['status'])) {
            Log::info('test ' . $this->rowNumber, [
                'raw_data' => $row,
                'headers_found' => array_keys($row),
                'maxEmptyRows' => $this->maxEmptyRows
            ]);
            $this->maxEmptyRows--;

            // Stop processing if max empty rows reached
            if ($this->maxEmptyRows <= 0) {
                Log::info('Max empty rows reached at row ' . $this->rowNumber);
                return null;
            }
        }


        if (empty($row['inventory_id']) && !empty($row['status'])) {
            $this->inventoryCategoryId = $this->getInventoryCategoryId($row['status']);
            return null;
        }

        $this->maxEmptyRows = 10;
        try {
            return ProductInventory::updateOrCreate(
                ['inventory_code' => $row['inventory_id']],
                [
                    'inventory_category_id' => $this->inventoryCategoryId,
                    'unit_of_measurement_id' => $this->getUnitOfMeasuremntId($row['uom']),
                    'name' => $row['inventory_name'],
                    'brand' => $row['brand'],
                    'category_a' => $row['category_a'],
                    'category_b' => $row['category_b'],
                    'packaging' => $row['packaging'],
                    'conversion' => $row['conversion'],
                    'cost' => $this->getCost(array_values($row)[10]),
                    'is_active' => $this->getStatus($row['status'])
                ]
            );
        } catch (\Exception $e) {
            $this->errors->push([
                'row' => $row,
                'error' => $e->getMessage()
            ]);
            Log::error('Error processing row ' . $this->rowNumber, [
                'error' => $e->getMessage(),
                'row' => $row
            ]);
            return null;
        }
    }

    public function getErrors(): Collection
    {
        return $this->errors;
    }

    public function rules(): array
    {
        return [
            // 'inventory_code' => ['required', 'string'],
            // 'name' => ['required', 'string'],
            // 'inventory_category' => ['required', 'string'],
            // 'uom' => ['required', 'string'],
            // 'conversion' => ['required', 'numeric'],
            // 'cost' => ['required'],
            // 'status' => ['required', 'string']
        ];
    }

    public function getCost($cost)
    {
        if (empty($cost)) {
            return 0;
        }

        $cleanCost = trim((string)$cost);

        $cleanCost = preg_replace('/[^0-9.-]/', '', $cleanCost);

        return is_numeric($cleanCost) ? (float)$cleanCost : 0;
    }

    public function getInventoryCategoryId($categoryName)
    {
        $categoryName = trim($categoryName);
        if (!isset($this->inventoryCategories[$categoryName])) {
            $inventoryCategory = InventoryCategory::create(['name' => $categoryName]);
            $this->inventoryCategories[$categoryName] = $inventoryCategory->id;
        }
        return  $this->inventoryCategories[$categoryName];
    }

    public function getUnitOfMeasuremntId($uomName)
    {
        $uomName = trim($uomName);
        if (!isset($this->inventoryCategories[$uomName])) {
            $inventoryCategory = UnitOfMeasurement::create(['name' => $uomName]);
            $this->inventoryCategories[$uomName] = $inventoryCategory->id;
        }
        return $this->unitOfMeasurements[$uomName] ?? null;
    }

    public function getStatus($status)
    {
        $status = trim($status);
        return $this->statuses[$status] ?? false;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function batchSize(): int
    {
        return 500;
    }
}
