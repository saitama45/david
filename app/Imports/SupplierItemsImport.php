<?php

namespace App\Imports;

use App\Models\SupplierItems;
use App\Models\SAPMasterfile; // Corrected to SAPMasterfile (was SapMasterfile)
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB; // Import DB facade for upsert

class SupplierItemsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedDetails = [];
    protected $processedCount = 0;
    protected $skippedEmptyKeysCount = 0;
    protected $skippedBySapValidationCount = 0;
    protected $skippedUnauthorizedCount = 0;
    protected $assignedSupplierCodes;

    public function __construct(array $assignedSupplierCodes)
    {
        $this->assignedSupplierCodes = $assignedSupplierCodes;
    }

    /**
     * @param Collection $rows
     *
     * @return void
     */
    public function collection(Collection $rows)
    {
        // Define a conservative batch size for the upsert operation to avoid SQL Server parameter limits.
        // This value is chosen to provide a significant buffer, making it less reliant on exact column counts.
        // Even if more columns are added later, this batch size should remain safe.
        $upsertBatchSize = 100; 

        // Group rows by ItemCode + UOM + SupplierCode to handle duplicates within the Excel chunk itself
        // This ensures that for a given combination, only the LAST occurrence in the Excel chunk is processed
        $deduplicatedRows = $rows->groupBy(function($row) {
            $itemCode = trim($row['item_code'] ?? '');
            $unit = trim($row['unit'] ?? '');
            $supplierCode = trim($row['supplier_code'] ?? '');
            return $itemCode . '|' . $unit . '|' . $supplierCode;
        })->map(function($group) {
            return $group->last(); // Take the last occurrence for each combination
        })->values(); // Re-index the collection

        $dataForCurrentChunk = [];

        foreach ($deduplicatedRows as $row) {
            $itemCode = trim($row['item_code'] ?? '');
            $supplierCode = trim($row['supplier_code'] ?? '');
            $unit = trim($row['unit'] ?? ''); // Excel's UNIT column (mapped as 'uom' in DB)

            // 1. Basic validation: Skip if ItemCode or SupplierCode are empty after trimming
            if (empty($itemCode) || empty($supplierCode)) {
                $this->skippedEmptyKeysCount++;
                $reason = 'Empty Item Code or Supplier Code';
                $details = [
                    'ItemCode' => $itemCode,
                    'SupplierCode' => $supplierCode
                ];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('SupplierItems Import Skipped (Empty Keys): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            // 2. Authorization Check: Ensure the supplier code is assigned to the current user
            if (!in_array($supplierCode, $this->assignedSupplierCodes)) {
                $this->skippedUnauthorizedCount++; // Increment this counter
                $reason = 'Unauthorized Supplier Code';
                $details = [
                    'ItemCode' => $itemCode,
                    'SupplierCode' => $supplierCode,
                    'UserAssignedSuppliers' => $this->assignedSupplierCodes // For debugging
                ];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('SupplierItems Import Skipped (Unauthorized): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            // 3. SAP Validation: Check if ItemCode and AltUOM (from Excel's UNIT) exist in sap_masterfiles
            // Use case-insensitive comparison for both ItemCode and AltUOM
            $sapMasterfileExists = SAPMasterfile::whereRaw('UPPER(ItemCode) = ?', [strtoupper($itemCode)])
                                                ->whereRaw('UPPER(AltUOM) = ?', [strtoupper($unit)])
                                                ->exists();

            if (!$sapMasterfileExists) {
                $this->skippedBySapValidationCount++;
                // Get all matching records for debugging
                $matchingRecords = SAPMasterfile::where('ItemCode', $itemCode)->get(['ItemCode', 'AltUOM', 'BaseUOM']);
                $reason = 'Item Code and UOM combination not found in SAP Masterfile';
                $details = [
                    'ItemCode' => $itemCode,
                    'UOM_from_Excel' => $unit,
                    'matching_sap_records' => $matchingRecords->map(fn($r) => ['AltUOM' => $r->AltUOM, 'BaseUOM' => $r->BaseUOM])->toArray()
                ];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('SupplierItems Import Skipped (SAP Validation): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            // If valid and authorized, proceed with data preparation
            $category = trim($row['category'] ?? '');
            $category2 = trim($row['category_2'] ?? '');
            $area = trim($row['area'] ?? '');
            $brand = trim($row['brand'] ?? '');
            $classification = trim($row['classification'] ?? '');
            $itemName = trim($row['item_name'] ?? '');
            $packagingConfig = trim($row['packaging_config'] ?? '');
            $cost = (float) ($row['cost'] ?? 0.00);
            $srp = (float) ($row['srp'] ?? 0.00);
            $sortOrder = (int) ($row['sort_order'] ?? 0);
            
            // Handle 'ACTIVE' column (0 or 1)
            $isActive = filter_var($row['active'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_null($isActive)) {
                $isActive = (int)($row['active'] == 1); // Fallback if not boolean, assume 1 is true
            }
            $isActive = (int)$isActive; // Ensure it's 0 or 1

            $dataForCurrentChunk[] = [
                'category'          => $category,
                'category2'        => $category2,
                'area'              => $area,
                'brand'             => $brand,
                'classification'    => $classification,
                'ItemCode'          => $itemCode,
                'item_name'         => $itemName,
                'packaging_config'  => $packagingConfig,
                'uom'               => $unit, // Maps to 'UNIT' column in Excel
                'cost'              => $cost,
                'srp'               => $srp,
                'SupplierCode'      => $supplierCode,
                'is_active'         => $isActive,
                'sort_order'        => $sortOrder,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
            $this->processedCount++;
        }

        // Now, process the collected dataForCurrentChunk in smaller batches for upsert
        if (!empty($dataForCurrentChunk)) {
            foreach (array_chunk($dataForCurrentChunk, $upsertBatchSize) as $miniBatch) {
                // Define the unique key for upserting: 'ItemCode', 'UOM', and 'SupplierCode'
                $uniqueBy = ['ItemCode', 'uom', 'SupplierCode'];

                // Define the columns that should be updated if a match is found
                // Exclude: id, ItemCode, uom, SupplierCode (these are the unique identifiers)
                $updateColumns = [
                    'category', 'category2', 'area', 'brand', 'classification', 'item_name', 'packaging_config',
                    'cost', 'srp', 'is_active', 'sort_order', 'updated_at'
                ];

                DB::table('supplier_items')->upsert($miniBatch, $uniqueBy, $updateColumns);
            }
        }
    }

    /**
     * Adds details of a skipped row to the collection.
     * @param \Illuminate\Support\Collection $row The original row data from Excel.
     * @param string $reason The reason for skipping.
     * @param array $specificDetails Any specific details related to the skip.
     */
    protected function addSkippedDetail(\Illuminate\Support\Collection $row, $reason, $specificDetails = [])
    {
        $this->skippedDetails[] = [
            'original_row' => $row->toArray(), // Store the original row as an array
            'reason' => $reason,
            'details' => $specificDetails,
            'timestamp' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getSkippedEmptyKeysCount(): int
    {
        return $this->skippedEmptyKeysCount;
    }

    public function getSkippedBySapValidationCount(): int
    {
        return $this->skippedBySapValidationCount;
    }

    public function getSkippedUnauthorizedCount(): int
    {
        return $this->skippedUnauthorizedCount;
    }

    public function getSkippedDetails(): array
    {
        return $this->skippedDetails;
    }

    // This is the chunk size for Maatwebsite\Excel to read the file
    public function chunkSize(): int
    {
        return 1000; // Reads 1000 rows at a time into the collection method
    }
}
