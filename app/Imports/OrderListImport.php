<?php

namespace App\Imports;

use App\Models\SupplierItems;
use App\Models\SAPMasterfile; // Import SAPMasterfile model
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB; // Import DB facade for DB::raw
use Illuminate\Support\Arr; // Import the Arr facade for data_get helper

class OrderListImport implements ToCollection, WithHeadingRow
{
    protected $importedData;
    protected $expectedSupplierCode; // New property to store the selected supplier code
    protected $skippedItems = []; // NEW: Property to store skipped items and their reasons

    /**
     * Constructor to receive the expected supplier code.
     * @param string|null $expectedSupplierCode The supplier code from the frontend dropdown.
     */
    public function __construct(string $expectedSupplierCode = null)
    {
        $this->expectedSupplierCode = $expectedSupplierCode;
    }

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
                $qtyFromExcelRaw = $row['qty'] ?? $row['Qty'] ?? $row['quantity'] ?? null; // Get raw value
                $costFromExcel = $row['cost'] ?? $row['Cost'] ?? null;
                $unitFromExcel = $row['unit'] ?? $row['Unit'] ?? $row['uom'] ?? $row['UOM'] ?? null; // Check for 'unit', 'Unit', 'uom', 'UOM'
                $supplierCodeFromExcel = $row['supplier_code'] ?? $row['Supplier Code'] ?? null; // Added supplier code

                // Convert to numeric types, handling potential non-numeric values and empty strings for quantity
                $qtyFromExcel = null;
                $trimmedQty = trim($qtyFromExcelRaw); // Trim whitespace from the raw quantity value

                if (is_numeric($trimmedQty)) {
                    $qtyFromExcel = (float) $trimmedQty;
                } elseif ($trimmedQty === '') {
                    $qtyFromExcel = 0.0; // Treat empty string (after trimming) as 0 quantity
                }
                // If it's not numeric and not an empty string (e.g., "abc"), it remains null.


                $costFromExcel = is_numeric($costFromExcel) ? (float) $costFromExcel : null;

                $logMessage = "OrderListImport: Extracted for row {$key} - ItemCode: '{$itemCodeFromExcel}', ItemName: '{$itemNameFromExcel}', Qty: '{$qtyFromExcel}', Cost: '{$costFromExcel}', Unit: '{$unitFromExcel}', SupplierCode: '{$supplierCodeFromExcel}'";
                Log::debug($logMessage);

                // --- Backend-side Validation for essential data from Excel ---
                if (empty($itemCodeFromExcel)) {
                    $reason = 'Item Code is missing or empty.';
                    Log::warning("OrderListImport: Skipping row {$key}: {$reason}");
                    $this->addSkippedItem($itemCodeFromExcel, $itemNameFromExcel, $reason);
                    return null;
                }
                if (empty($supplierCodeFromExcel)) {
                    $reason = 'Supplier Code is missing or empty.';
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': {$reason}");
                    $this->addSkippedItem($itemCodeFromExcel, $itemNameFromExcel, $reason);
                    return null;
                }
                // NEW VALIDATION: Check if supplier code from Excel matches the expected supplier code
                if ($this->expectedSupplierCode && $supplierCodeFromExcel !== $this->expectedSupplierCode) {
                    $reason = "Supplier Code '{$supplierCodeFromExcel}' from Excel does not match selected supplier '{$this->expectedSupplierCode}'.";
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': {$reason}");
                    $this->addSkippedItem($itemCodeFromExcel, $itemNameFromExcel, $reason);
                    return null; // Skip this row
                }

                // Allow 0 here for Qty and Cost, frontend will filter based on > 0.1 and > 0 respectively.
                if ($qtyFromExcel === null || $qtyFromExcel < 0) { 
                    $reason = 'Quantity is missing or invalid (negative).';
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': {$reason}");
                    $this->addSkippedItem($itemCodeFromExcel, $itemNameFromExcel, $reason);
                    return null;
                }
                if ($costFromExcel === null || $costFromExcel < 0) { 
                    $reason = 'Cost is missing or invalid (negative).';
                    Log::warning("OrderListImport: Skipping row {$key} for item '{$itemCodeFromExcel}': {$reason}");
                    $this->addSkippedItem($itemCodeFromExcel, $itemNameFromExcel, $reason);
                    return null;
                }
                // --- End Backend-side Validation ---

                // Fetch SupplierItem without eager loading sapMasterfiles, as we'll query it directly
                $supplierItem = SupplierItems::where('ItemCode', $itemCodeFromExcel)
                                             ->where('is_active', true) // Only import active items
                                             ->where('SupplierCode', $supplierCodeFromExcel)
                                             ->first();

                if (!$supplierItem) {
                    $reason = "Item not found or inactive for Item Code: '{$itemCodeFromExcel}' and Supplier Code: '{$supplierCodeFromExcel}'.";
                    Log::warning("OrderListImport: Skipping row {$key}: {$reason}");
                    $this->addSkippedItem($itemCodeFromExcel, $itemNameFromExcel, $reason);
                    return null;
                }
                Log::debug("OrderListImport: SupplierItems found for '{$itemCodeFromExcel}': " . json_encode($supplierItem->toArray()));

                // Use the quantity directly from Excel.
                $finalQuantity = $qtyFromExcel; 
                
                // Use cost from Excel directly for mapped row and total cost calculation
                $finalCost = $costFromExcel; 
                $totalCost = $finalCost * $finalQuantity;

                // CRITICAL FIX: Directly query SAPMasterfile to get the exact matching entry
                $baseQtyForCalculation = 1; // Default fallback
                $baseUomForMapping = null;
                $retrievedBaseQtyLog = 'N/A (no matching SAPMasterfile)';

                $cleanedSupplierUOM = strtoupper(trim($supplierItem->uom));

                $matchedSapMasterfile = SAPMasterfile::where('ItemCode', $itemCodeFromExcel)
                                                     ->where(DB::raw('UPPER(AltUOM)'), $cleanedSupplierUOM)
                                                     ->where('is_active', true) // Ensure only active SAP masterfile entries are considered
                                                     ->first();

                if ($matchedSapMasterfile) {
                    // FIXED: Changed BaseQTY to BaseQty
                    $retrievedBaseQtyLog = $matchedSapMasterfile->BaseQty ?? 'N/A (direct access fallback)';
                    $baseQtyForCalculation = (float) $retrievedBaseQtyLog;
                    
                    if ($baseQtyForCalculation <= 0) {
                        $baseQtyForCalculation = 1;
                    }
                    $baseUomForMapping = $matchedSapMasterfile->BaseUOM ?? null;
                } else {
                    $anySapMasterfile = SAPMasterfile::where('ItemCode', $itemCodeFromExcel)
                                                     ->where('is_active', true)
                                                     ->first();
                    if ($anySapMasterfile) {
                        // FIXED: Changed BaseQTY to BaseQty
                        $baseQtyForCalculation = (float) ($anySapMasterfile->BaseQty ?? 1);
                        if ($baseQtyForCalculation <= 0) {
                            $baseQtyForCalculation = 1;
                        }
                        $baseUomForMapping = $anySapMasterfile->BaseUOM ?? null;
                        $retrievedBaseQtyLog = "Fallback to any SAP entry: " . ($anySapMasterfile->BaseQty ?? 'N/A');
                    }
                }
                
                Log::debug("OrderListImport: BaseQTY from direct SAPMasterfile query for '{$itemCodeFromExcel}': {$retrievedBaseQtyLog} (Used for calculation: {$baseQtyForCalculation})");


                Log::debug("OrderListImport: Final values for '{$itemCodeFromExcel}' - Quantity: {$finalQuantity}, Cost: {$finalCost}, Total Cost: {$totalCost}");

                $mappedRow = [
                    'id' => $supplierItem->id, // Use supplierItem->id for consistency
                    'inventory_code' => $supplierItem->ItemCode,
                    'name' => $supplierItem->item_name,
                    'cost' => $finalCost, // Use the Excel cost here
                    'unit_of_measurement' => $supplierItem->uom ?? $unitFromExcel, // Prefer DB UOM, fallback to Excel
                    'base_uom' => $baseUomForMapping, // Use the determined BaseUOM
                    'base_qty' => $baseQtyForCalculation, // Use the explicitly determined baseQty
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

    /**
     * Adds a skipped item to the internal list.
     *
     * @param string|null $itemCode
     * @param string|null $itemName
     * @param string $reason
     * @return void
     */
    protected function addSkippedItem(?string $itemCode, ?string $itemName, string $reason): void
    {
        $this->skippedItems[] = [
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'reason' => $reason,
        ];
    }

    /**
     * Get the imported data collection.
     *
     * @return Collection
     */
    public function getImportedData(): Collection
    {
        return $this->importedData;
    }

    /**
     * Get the list of skipped items.
     *
     * @return array
     */
    public function getSkippedItems(): array
    {
        return $this->skippedItems;
    }
}
