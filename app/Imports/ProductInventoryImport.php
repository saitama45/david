<?php

namespace App\Imports;

use App\Models\InventoryCategory;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ProductInventoryImport implements ToModel, WithHeadingRow, WithValidation
{
    use Importable;
    private $productCategories;
    private $unitOfMeasurements;
    private $inventoryCategories;
    private $statuses;
    private $errors;

    public function __construct()
    {
        $this->statuses = [
            'Active' => true,
            'Inactive' => true
        ];
        $this->productCategories = array_flip(ProductCategory::options()->toArray());
        $this->unitOfMeasurements = array_flip(UnitOfMeasurement::options()->toArray());
        $this->inventoryCategories = array_flip(InventoryCategory::options()->toArray());
    }
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        try {
            return ProductInventory::updateOrCreate(
                ['inventory_code' => $row['inventory_code']],
                [
                    'inventory_category_id' => $this->getInventoryCategoryId($row['inventory_category']),
                    'unit_of_measurement_id' => $this->getUnitOfMeasuremntId($row['uom']),
                    'name' => $row['name'],
                    'brand' => $row['brand'],
                    'conversion' => $row['conversion'],
                    'cost' => $this->getCost($row['cost']),
                    'is_active' => $this->getStatus($row['status'])
                ]
            );
        } catch (\Exception $e) {
            $this->errors->push([
                'row' => $row,
                'error' => $e->getMessage()
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
            'inventory_code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'inventory_category' => ['required', 'string'],
            'uom' => ['required', 'string'],
            'conversion' => ['required', 'numeric'],
            'cost' => ['required'],
            'status' => ['required', 'string']
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
        return  $this->inventoryCategories[$categoryName];
    }

    public function getUnitOfMeasuremntId($uomName)
    {
        $uomName = trim($uomName);
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
