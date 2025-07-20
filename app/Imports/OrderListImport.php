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
                // Note: Quantity and Cost validation will be done on the frontend to match user's exact requirement
                // "We need to skipped these 0 cost and 0 qty. We just import those who have > 0 in cost and qty."
                // This means the backend should pass all validly structured rows, and frontend filters.
                // However, for robustness, we'll keep basic checks here to prevent obvious garbage data.
                if ($qtyFromExcel === null || $qtyFromExcel < 0) { // Allow 0 here, frontend will filter
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': 'Qty' is missing or invalid (negative).");
                    return null;
                }
                if ($costFromExcel === null || $costFromExcel < 0) { // Allow 0 here, frontend will filter
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': 'Cost' is missing or invalid (negative).");
                    return null;
                }
                // --- End Backend-side Validation ---

                $supplierItem = SupplierItems::with('sapMasterfile')
                                ->where('ItemCode', $itemCodeFromExcel)
                                ->where('SupplierCode', $supplierCodeFromExcel)
                                ->first();

                if (!$supplierItem) {
                    Log::warning("OrderListImport: Skipping row {$key}: SupplierItems not found for Item Code: '{$itemCodeFromExcel}' and Supplier Code: '{$supplierCodeFromExcel}'.");
                    return null;
                }
                Log::debug("OrderListImport: SupplierItems found for '{$itemCodeFromExcel}': " . json_encode($supplierItem->toArray()));

                $calculatedQuantity = $qtyFromExcel;
                if (is_string($unitFromExcel) && str_contains($unitFromExcel, '(') && str_contains($unitFromExcel, ')')) {
                    $start = strpos($unitFromExcel, '(') + 1;
                    $end = strpos($unitFromExcel, ')');
                    $conversionString = substr($unitFromExcel, $start, $end - $start);
                    if (is_numeric($conversionString)) {
                        $conversionFactor = (float) $conversionString;
                        $calculatedQuantity = $conversionFactor * $qtyFromExcel;
                        Log::debug("OrderListImport: Unit conversion applied for '{$unitFromExcel}'. Original Qty: {$qtyFromExcel}, Conversion Factor: {$conversionFactor}, Calculated Qty: {$calculatedQuantity}");
                    } else {
                        Log::warning("OrderListImport: Invalid conversion factor in unit '{$unitFromExcel}' for item '{$itemCodeFromExcel}'. Using raw quantity.");
                    }
                }

                // CRITICAL FIX: Use cost from Excel directly for mapped row and total cost calculation
                // This ensures the frontend receives the Excel cost for its validation.
                $finalCost = $costFromExcel; 
                $totalCost = $finalCost * $calculatedQuantity;

                Log::debug("OrderListImport: Calculated Total Cost for '{$itemCodeFromExcel}': {$totalCost} (Excel Cost: {$finalCost}, Calculated Qty: {$calculatedQuantity})");

                $mappedRow = [
                    'id' => $supplierItem->id,
                    'inventory_code' => $supplierItem->ItemCode,
                    'name' => $supplierItem->item_name,
                    'cost' => $finalCost, // Use the Excel cost here
                    'unit_of_measurement' => $supplierItem->uom ?? $unitFromExcel,
                    'base_uom' => $supplierItem->sapMasterfile->BaseUOM ?? null,
                    'total_cost' => $totalCost,
                    'quantity' => $calculatedQuantity,
                    'uom' => $supplierItem->uom ?? $unitFromExcel
                ];
                Log::debug("OrderListImport: Mapped row {$key} output: " . json_encode($mappedRow));
                return $mappedRow;
            })
            ->filter()
            ->values();
        
        Log::debug("OrderListImport: Finished collection processing. Final importedData count: " . $this->importedData->count());
        Log::debug("OrderListImport: Final importedData: " . json_encode($this->importedData->toArray()));
    }

    public function getImportedData()
    {
        return $this->importedData;
    }
}
