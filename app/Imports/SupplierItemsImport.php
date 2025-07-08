<?php

namespace App\Imports;

use App\Models\SupplierItems;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow; // Make sure this trait is imported
use Maatwebsite\Excel\Concerns\WithBatchInserts; // For performance with large datasets
use Maatwebsite\Excel\Concerns\WithChunkReading; // For performance with very large datasets
use Illuminate\Support\Collection;


class SupplierItemsImport implements ToCollection, WithHeadingRow, WithBatchInserts, WithChunkReading  //, WithValidation, SkipsOnError, SkipsOnFailure
{
    /**
     * @param Collection $rows
     *
     * @return void
     */
    public function collection(Collection $rows)
    {
        $processedRows = $rows->map(function ($row) {
            // Ensure values are trimmed and default to empty string if not present
            $itemNo = trim($row['item_code'] ?? '');
            $supplierCode = trim($row['supplier_code'] ?? '');
            
            // Get 'active' status from Excel. Default to 1 if not provided or invalid.
            // Convert to boolean or integer 0/1 as per database expectation.
            $isActive = filter_var($row['active'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_null($isActive)) {
                $isActive = (int)($row['active'] == 1); // Fallback for values like "Yes"/"No" if they appear, though you specified 0/1
            }
            $isActive = (int)$isActive; // Ensure it's 0 or 1

            return [
                'ItemNo'       => $itemNo,
                'SupplierCode' => $supplierCode,
                'is_active'    => $isActive,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        })->unique(function ($item) {
            // **Crucial Change:** Unique by 'ItemNo' only
            return $item['ItemNo'];
        })->values(); // Re-index the collection after unique()

        $dataToUpsert = $processedRows->toArray();

        // Define the column that uniquely identifies a record for matching
        $uniqueBy = ['ItemNo'];

        // Define the columns that should be updated if a match is found
        // Now includes 'SupplierCode' and 'is_active' in the update list
        $updateColumns = ['SupplierCode', 'is_active', 'updated_at'];

        SupplierItems::upsert($dataToUpsert, $uniqueBy, $updateColumns);
    }

    /**
     * Define the batch size for batch inserts.
     * This still helps with memory usage even with manual upserting,
     * as `collection` will be called for each chunk.
     * @return int
     */
    public function batchSize(): int
    {
        return 200; // Adjust based on your server's memory and database performance
    }

    /**
     * Define the chunk size for chunk reading.
     * This helps process very large files without running out of memory.
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000; // Adjust as needed
    }
    /*
    // Optional: Implement WithValidation trait for row-level validation
    // If you enable this, ensure 'item_code' and 'supplier_code' are properly validated
    // and consider trimming them before validation if rules like 'unique' are used.
    public function rules(): array
    {
        return [
            'item_code' => 'required|string|max:255',
            'supplier_code' => 'required|string|max:255',
            // ... other rules
        ];
    }
    */
}