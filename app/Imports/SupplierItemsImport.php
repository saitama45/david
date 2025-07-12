<?php

namespace App\Imports;

use App\Models\SupplierItems;
use App\Models\SapMasterfile; // Import the SapMasterfile model
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SupplierItemsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    /**
     * @param Collection $rows
     *
     * @return void
     */
    public function collection(Collection $rows)
    {
        $processedCount = 0;
        $skippedEmptyKeysCount = 0;
        $skippedBySapValidationCount = 0; // New counter for SAP validation skips

        foreach ($rows as $row) {
            // Trim values and provide defaults
            $category = trim($row['category'] ?? '');
            $brand = trim($row['brand'] ?? '');
            $classification = trim($row['classification'] ?? '');
            $itemCode = trim($row['item_code'] ?? '');
            $itemName = trim($row['item_name'] ?? '');
            $packagingConfig = trim($row['packaging_configuration'] ?? '');
            $unit = trim($row['unit'] ?? ''); // This is your Excel 'UNIT' column, maps to 'uom' in SupplierItems
            $cost = (float) ($row['cost'] ?? 0.00);
            $srp = (float) ($row['srp'] ?? 0.00);
            $supplierCode = trim($row['supplier_code'] ?? '');
            
            $isActive = filter_var($row['active'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_null($isActive)) {
                $isActive = (int)($row['active'] == 1);
            }
            $isActive = (int)$isActive;

            // 1. Basic validation: Skip if ItemCode or SupplierCode are empty
            if (empty($itemCode) || empty($supplierCode)) {
                $skippedEmptyKeysCount++;
                Log::warning('SupplierItems Import: Skipping row due to empty ItemCode or SupplierCode.', [
                    'row_data' => $row->toArray(),
                ]);
                continue;
            }

            // 2. NEW VALIDATION: Check if ItemCode and AltUOM (from Excel's UNIT) exist in sap_masterfiles
            // Assuming 'unit' from Excel maps to 'AltUOM' in sap_masterfiles for this check.
            $sapMasterfileExists = SapMasterfile::where('ItemCode', $itemCode)
                                                ->where('AltUOM', $unit) // Use $unit from Excel for AltUOM
                                                ->exists();

            if (!$sapMasterfileExists) {
                $skippedBySapValidationCount++;
                Log::warning('SupplierItems Import: Skipping row. ItemCode and AltUOM (UNIT) combination not found in sap_masterfiles.', [
                    'item_code' => $itemCode,
                    'alt_uom_from_excel' => $unit,
                    'row_data' => $row->toArray(),
                ]);
                continue; // Skip to the next row if not found in SAP masterfile
            }

            // Prepare data for find/update/create (keys must match SupplierItems DB columns)
            $data = [
                'category'          => $category,
                'brand'             => $brand,
                'classification'    => $classification,
                'ItemCode'          => $itemCode,
                'item_name'         => $itemName,
                'packaging_config'  => $packagingConfig,
                'config'            => 0.00, // Hardcoded as it's in DB but not Excel
                'uom'               => $unit, // Maps Excel 'UNIT' to SupplierItems 'uom'
                'cost'              => $cost,
                'srp'               => $srp,
                'SupplierCode'      => $supplierCode,
                'is_active'         => $isActive,
            ];

            // Define the unique attributes for finding the SupplierItems record
            $uniqueAttributes = [
                'ItemCode' => $itemCode,
                'SupplierCode' => $supplierCode,
            ];

            try {
                SupplierItems::updateOrCreate($uniqueAttributes, $data);
                $processedCount++;

            } catch (\Exception $e) {
                Log::error('SupplierItems Import: Error processing row for ItemCode: ' . $itemCode . ', SupplierCode: ' . $supplierCode . '. Error: ' . $e->getMessage(), [
                    'row_data' => $data,
                    'exception' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('SupplierItems Import: Finished processing Excel file.', [
            'total_rows_from_excel' => $rows->count(),
            'successfully_processed_rows' => $processedCount,
            'skipped_rows_due_to_empty_keys' => $skippedEmptyKeysCount,
            'skipped_rows_due_to_sap_validation' => $skippedBySapValidationCount, // New log entry
        ]);
    }

    public function batchSize(): int
    {
        return 1; // Remains 1 for updateOrCreate strategy
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}