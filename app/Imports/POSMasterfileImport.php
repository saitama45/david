<?php

namespace App\Imports;

use App\Models\POSMasterfile;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;


class POSMasterfileImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading //, WithValidation, SkipsOnError, SkipsOnFailure
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
        $itemCode = (string) ($row['item_code'] ?? '');
        $itemDescription = (string) ($row['item_description'] ?? '');
        $category = (string) ($row['category'] ?? ''); // Convert to string before hashing
        $subCategory = (string) ($row['subcategory'] ?? '');

        // Default is_active to 1 as per your previous logic for imported items.
        $is_active = 1;

        // Return the model instance. Eloquent will handle timestamps automatically.
        return new POSMasterfile([
            'ItemCode' => $itemCode,
            'ItemDescription' => $itemDescription,
            'Category' => $category,
            'SubCategory' => $subCategory,
            'SRP' => (float) str_replace(',', '', ($row['srp'] ?? 0)),
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