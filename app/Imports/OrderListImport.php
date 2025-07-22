<?php

namespace App\Imports;

use App\Models\SupplierItems;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class OrderListImport implements ToCollection, WithHeadingRow
{
    protected $importedData;

    /**
     * @param Collection $collection
     */
    public function collection(Collection $collection)
    {
        Log::debug("OrderListImport: Starting collection processing.");
        Log::debug("OrderListImport: Raw collection received (" . $collection->count() . " rows): " . json_encode($collection->toArray()));

        $this->importedData = $collection
            ->map(function ($row, $key) {
                Log::debug("OrderListImport: Processing row {$key}. Raw row data: " . json_encode($row->toArray()));

                // Safely access Excel column data, considering potential casing variations
                $itemCodeFromExcel = $row['item_code'] ?? $row['Item Code'] ?? null;
                $itemNameFromExcel = $row['item_name'] ?? $row['Item Name'] ?? null;
                $qtyFromExcel = $row['qty'] ?? $row['Qty'] ?? $row['quantity'] ?? null; // Check for 'qty', 'Qty', 'quantity'
                $costFromExcel = $row['cost'] ?? $row['Cost'] ?? null;
                $unitFromExcel = $row['unit'] ?? $row['Unit'] ?? $row['uom'] ?? $row['UOM'] ?? null; // Check for 'unit', 'Unit', 'uom', 'UOM'
                $supplierCodeFromExcel = $row['supplier_code'] ?? $row['Supplier Code'] ?? null; // Added supplier code

                // Convert to numeric types, handling potential non-numeric values
                $qtyFromExcel = is_numeric($qtyFromExcel) ? (float) $qtyFromExcel : null;
                $costFromExcel = is_numeric($costFromExcel) ? (float) $costFromExcel : null;

                Log::debug("OrderListImport: Extracted for row {$key} - ItemCode: '{$itemCodeFromExcel}', ItemName: '{$itemNameFromExcel}', Qty: '{$qtyFromExcel}', Cost: '{$costFromExcel}', Unit: '{$unitFromExcel}', SupplierCode: '{$supplierCodeFromExcel}'");

                // --- Backend-side Validation for essential data from Excel ---
                if (empty($itemCodeFromExcel)) {
                    Log::warning("OrderListImport: Skipping row {$key}: 'Item Code' is missing or empty.");
                    return null;
                }
                if (empty($supplierCodeFromExcel)) {
                    Log::warning("OrderListImport: Skipping row {$key}: 'Supplier Code' is missing or empty for item '{$itemCodeFromExcel}'.");
                    return null;
                }
                // Allow 0 here for Qty and Cost, frontend will filter based on > 0.1 and > 0 respectively.
                if ($qtyFromExcel === null || $qtyFromExcel < 0) { 
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': 'Qty' is missing or invalid (negative).");
                    return null;
                }
                if ($costFromExcel === null || $costFromExcel < 0) { 
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': 'Cost' is missing or invalid (negative).");
                    return null;
                }
                // --- End Backend-side Validation ---

                $supplierItem = SupplierItems::with('sapMasterfile')
                                     ->where('ItemCode', $itemCodeFromExcel)
                                     ->where('is_active', true) // Only import active items
                                     ->where('SupplierCode', $supplierCodeFromExcel)
                                     ->first();

                if (!$supplierItem) {
                    Log::warning("OrderListImport: Skipping row {$key}: SupplierItems not found or inactive for Item Code: '{$itemCodeFromExcel}' and Supplier Code: '{$supplierCodeFromExcel}'.");
                    return null;
                }
                Log::debug("OrderListImport: SupplierItems found for '{$itemCodeFromExcel}': " . json_encode($supplierItem->toArray()));

                // CRITICAL FIX: Use the quantity directly from Excel.
                // The Excel 'Qty' column is assumed to be the final desired quantity.
                $finalQuantity = $qtyFromExcel; 
                
                // CRITICAL FIX: Use cost from Excel directly for mapped row and total cost calculation
                $finalCost = $costFromExcel; 
                $totalCost = $finalCost * $finalQuantity;

                Log::debug("OrderListImport: Final values for '{$itemCodeFromExcel}' - Quantity: {$finalQuantity}, Cost: {$finalCost}, Total Cost: {$totalCost}");

                $mappedRow = [
                    'id' => $supplierItem->id, // Use supplierItem->id for consistency
                    'inventory_code' => $supplierItem->ItemCode,
                    'name' => $supplierItem->item_name,
                    'cost' => $finalCost, // Use the Excel cost here
                    'unit_of_measurement' => $supplierItem->uom ?? $unitFromExcel, // Prefer DB UOM, fallback to Excel
                    'base_uom' => $supplierItem->sapMasterfile->BaseUOM ?? null,
                    'total_cost' => $totalCost,
                    'quantity' => $finalQuantity, // This is the fixed quantity
                    'uom' => $supplierItem->uom ?? $unitFromExcel // Use DB UOM, fallback to Excel
                ];
                Log::debug("OrderListImport: Mapped row {$key} output: " . json_encode($mappedRow));
                return $mappedRow;
            })
            ->filter() // Remove any null entries (skipped rows)
            ->values(); // Re-index the array

        Log::debug("OrderListImport: Finished collection processing. Final importedData count: " . $this->importedData->count());
        Log::debug("OrderListImport: Final importedData: " . json_encode($this->importedData->toArray()));
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
