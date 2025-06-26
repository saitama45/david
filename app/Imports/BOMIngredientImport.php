<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\ProductInventory;
use App\Models\WIP;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BOMIngredientImport implements ToCollection, WithHeadingRow
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
            if (empty($rowArray['pos_code'])) {
                continue;
            }

            $sap_code = trim($rowArray['pos_code']);
            $inventory_code = trim($rowArray['item_code']);

            // Validate required fields
            if (empty($sap_code)) {
                $this->errors[] = "Row {$rowNumber}: SAP code is required";
                continue;
            }

            if (empty($inventory_code)) {
                $this->errors[] = "Row {$rowNumber}: Inventory code is required";
                continue;
            }

            if (empty($rowArray['menu_item'])) {
                Log::info('Missing name for SAP code', ['row' => $rowArray, 'row_number' => $rowNumber]);
                $this->errors[] = "Row {$rowNumber}: Name is required for SAP code {$sap_code}";
                continue;
            }

            if (!is_numeric($rowArray['bom_qty'])) {
                $this->errors[] = "Row {$rowNumber}: Valid quantity is required for SAP code {$sap_code}";
                continue;
            }

            if (empty($rowArray['uom'])) {
                $this->errors[] = "Row {$rowNumber}: Unit of measure is required for SAP code {$sap_code}";
                continue;
            }

            // Check if product inventory exists
            $product = ProductInventory::select(['id'])
                ->where('inventory_code', $inventory_code)
                ->first();

            $wip = WIP::select(['id'])
                ->where('sap_code', $inventory_code)
                ->first();

            Log::info('test', ['product' => $product, 'wip' => $wip]);

            if (!$product && !$wip) {
                $this->errors[] = "Row {$rowNumber}: Product inventory/WIP not found for code '{$inventory_code}' (SAP: {$sap_code}). Please make sure that your inventory/wip is updated before proceeding.";
                continue;
            }

            // Store validated row data with product ID for later processing
            $this->validatedRows[] = [
                'pos_code' => $sap_code,
                'item_code' => $inventory_code,
                'name' => $rowArray['menu_item'],
                'qty' => $rowArray['bom_qty'] ?? 0,
                'uom' => $rowArray['uom'],
                'product_id' => $product ? $product->id : null,
                'wip_id' => $wip ? $wip->id : null,
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
            $inventoryCode = $rowData['item_code'];
            $sapCode = $rowData['pos_code'];
            $wipId = $rowData['wip_id'];
            $productId = $rowData['product_id'];

            $menu = Menu::firstOrCreate(
                ['product_id' => $sapCode],
                [
                    'name' => $rowData['name'],
                    'remarks' => $rowData['remarks'] ?? null
                ]
            );

            Log::info('Processing WIP Ingredient', [
                'row_data' => $rowData,
            ]);


            // Create or update WIP ingredient
            $menu->menu_ingredients()->updateOrCreate(
                ['product_inventory_id' => $productId, 'wip_id' => $wipId],
                [
                    'product_inventory_id' => $wipId ? null : $productId,
                    'sap_code' => $sapCode,
                    'wip_id' => $wipId,
                    'quantity' => $rowData['qty'],
                    'unit' => $rowData['uom']
                ]
            );
        } catch (Exception $e) {
            Log::error('WIP Ingredient Import Processing Error', [
                'sap_code' => $rowData['pos_code'],
                'error' => $e->getMessage(),
                'row_number' => $rowData['row_number']
            ]);

            // Re-throw to trigger transaction rollback
            throw new Exception("Error processing row {$rowData['row_number']} (SAP: {$rowData['pos_code']}): " . $e->getMessage());
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
