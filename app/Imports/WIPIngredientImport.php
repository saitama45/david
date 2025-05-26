<?php

namespace App\Imports;

use App\Models\ProductInventory;
use App\Models\WIP;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Exception;

class WIPIngredientImport implements ToCollection, WithHeadingRow
{
    protected $errors = [];
    protected $validatedRows = [];

    public function collection(Collection $collection)
    {
        // Phase 1: Validate all rows first
        $this->validateAllRows($collection);

        // If there are any errors, don't proceed with import
        if (!empty($this->errors)) {
            throw new Exception('Validation failed: ' . implode('; ', $this->errors));
        }

        // Phase 2: Process all rows (wrapped in transaction for safety)
        DB::transaction(function () {
            foreach ($this->validatedRows as $rowData) {
                $this->processValidatedRow($rowData);
            }
        });
    }

    protected function validateAllRows(Collection $collection)
    {
        $rowNumber = 1; // Start from 1 (excluding header)

        foreach ($collection as $row) {
            $rowNumber++;
            $rowArray = $row->toArray();

            // Skip empty sap_code rows
            if (empty($rowArray['sap_code'])) {
                continue;
            }

            $sap_code = trim($rowArray['sap_code']);
            $inventory_code = trim($rowArray['inventory_code']);

            // Validate required fields
            if (empty($sap_code)) {
                $this->errors[] = "Row {$rowNumber}: SAP code is required";
                continue;
            }

            if (empty($inventory_code)) {
                $this->errors[] = "Row {$rowNumber}: Inventory code is required";
                continue;
            }

            if (empty($rowArray['name'])) {
                $this->errors[] = "Row {$rowNumber}: Name is required for SAP code {$sap_code}";
                continue;
            }

            if (empty($rowArray['qty']) || !is_numeric($rowArray['qty'])) {
                $this->errors[] = "Row {$rowNumber}: Valid quantity is required for SAP code {$sap_code}";
                continue;
            }

            if (empty($rowArray['uom'])) {
                $this->errors[] = "Row {$rowNumber}: Unit of measure is required for SAP code {$sap_code}";
                continue;
            }

            // Check if product inventory exists
            $product = ProductInventory::select(['id'])->where('inventory_code', $inventory_code)->first();

            if (!$product) {
                $this->errors[] = "Row {$rowNumber}: Product inventory not found for code '{$inventory_code}' (SAP: {$sap_code}). Please make sure that your inventory is updated before proceeding.";
                continue;
            }

            // Store validated row data with product ID for later processing
            $this->validatedRows[] = [
                'sap_code' => $sap_code,
                'inventory_code' => $inventory_code,
                'name' => $rowArray['name'],
                'qty' => $rowArray['qty'],
                'uom' => $rowArray['uom'],
                'product_id' => $product->id,
                'row_number' => $rowNumber
            ];
        }

        // Log validation results
        if (empty($this->errors)) {
            Log::info('WIP Ingredient Import Validation Passed', [
                'total_rows' => count($this->validatedRows)
            ]);
        } else {
            Log::error('WIP Ingredient Import Validation Failed', [
                'errors' => $this->errors,
                'total_errors' => count($this->errors)
            ]);
        }
    }

    protected function processValidatedRow(array $rowData)
    {
        try {
            // Create or get WIP
            $wip = WIP::firstOrCreate(
                ['sap_code' => $rowData['sap_code']],
                [
                    'sap_code' => $rowData['sap_code'],
                    'name' => $rowData['name']
                ]
            );

            Log::info('WIP Ingredient Import Processing', [
                'sap_code' => $rowData['sap_code'],
                'inventory_code' => $rowData['inventory_code'],
                'wip_id' => $wip->id,
                'row_number' => $rowData['row_number']
            ]);

            // Create or update WIP ingredient
            $wip->wip_ingredients()->updateOrCreate(
                ['product_inventory_id' => $rowData['product_id']],
                [
                    'product_inventory_id' => $rowData['product_id'],
                    'quantity' => $rowData['qty'],
                    'unit' => $rowData['uom']
                ]
            );
        } catch (Exception $e) {
            Log::error('WIP Ingredient Import Processing Error', [
                'sap_code' => $rowData['sap_code'],
                'error' => $e->getMessage(),
                'row_number' => $rowData['row_number']
            ]);

            // Re-throw to trigger transaction rollback
            throw new Exception("Error processing row {$rowData['row_number']} (SAP: {$rowData['sap_code']}): " . $e->getMessage());
        }
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getProcessedCount()
    {
        return count($this->validatedRows);
    }
}
