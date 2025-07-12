<?php

namespace App\Imports;

use App\Models\SAPMasterfile;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection; // Make sure this is imported if used elsewhere, though not strictly needed for this file based on provided code.


class SAPMasterfileImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading //, WithValidation, SkipsOnError, SkipsOnFailure
{
    /**
     * Static property to store hashes of combinations already processed during the current import run.
     * This is crucial for checking duplicates across different chunks.
     * It must be reset before each new import operation.
     * @var array
     */
    private static $seenCombinations = [];

    /**
     * Static method to reset the seen combinations tracker.
     * Called by the controller before a new import starts.
     */
    public static function resetSeenCombinations()
    {
        self::$seenCombinations = [];
    }

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        // Get the relevant fields, ensuring they are cast to string for consistent hashing.
        // Use empty string as default if key does not exist or value is null, for consistent hashing.
        $itemCode = (string) ($row['item_no'] ?? '');
        $itemDescription = (string) ($row['item_description'] ?? '');
        $baseQty = (string) ($row['baseqty'] ?? ''); // Convert to string before hashing
        $altUOM = (string) ($row['altuom'] ?? '');

        // Create a unique hash for the combination of these four fields
        $combinationKey = md5($itemCode . $itemDescription . $baseQty . $altUOM);

        // Check if this combination has already been seen in this import run.
        // If it has, return null to skip this row (allow only the first occurrence).
        if (isset(self::$seenCombinations[$combinationKey])) {
            return null; // This row is a duplicate within the import file, so skip it.
        }

        // Mark this combination as seen for the current import run.
        self::$seenCombinations[$combinationKey] = true;

        // Default is_active to 1 as per your previous logic for imported items.
        $is_active = 1;

        // Prepare the data for model creation.
        // Ensure BaseUOM is not null, as it's required in your model's validation.
        $baseUOM = (string) ($row['baseuom'] ?? 'N/A');
        if (empty($baseUOM) || $baseUOM === 'N/A') {
            // Optionally, you might log this or handle it as an error,
            // or use a default that matches your requirements more strictly.
            // For now, it will be 'N/A' if the Excel column is empty or missing.
        }

        // Return the model instance. Eloquent will handle timestamps automatically.
        return new SAPMasterfile([
            'ItemCode' => $itemCode,
            'ItemDescription' => $itemDescription,
            'AltQty' => (float) ($row['altqty'] ?? 0),
            'BaseQty' => (float) ($row['baseqty'] ?? 0),
            'AltUOM' => $altUOM,
            'BaseUOM' => $baseUOM,
            'is_active' => $is_active,
        ]);
    }

    /**
     * Define the batch size for batch inserts.
     * This improves performance by inserting multiple rows at once.
     * @return int
     */
    public function batchSize(): int
    {
        return 200; // You can adjust this number based on your server's memory and database performance
    }

    /**
     * Define the chunk size for chunk reading.
     * This helps process very large files without running out of memory.
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000; // You can adjust this number
    }


    /*
    // Optional: Implement WithValidation trait for row-level validation
    // ... (Your existing validation rules if any)
    */
}