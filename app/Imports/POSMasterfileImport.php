<?php

namespace App\Imports;

use App\Models\POSMasterfile;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithUpserts; // Added for upsert functionality
use Illuminate\Support\Facades\Log; // Added for logging

class POSMasterfileImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading, WithUpserts
{
    /**
     * Property to store skipped items and their reasons.
     * @var array
     */
    protected $skippedItems = [];

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
        Log::debug('POSMasterfileImport: resetSeenCombinations called.');
    }

    /**
     * Adds a skipped item to the internal list.
     *
     * @param string|null $itemCode
     * @param string|null $itemDescription
     * @param string $reason
     * @return void
     */
    protected function addSkippedItem(?string $itemCode, ?string $itemDescription, string $reason): void
    {
        $this->skippedItems[] = [
            'item_code' => $itemCode,
            'item_description' => $itemDescription,
            'reason' => $reason,
        ];
        Log::warning("POSMasterfileImport: Skipped item - ItemCode: '{$itemCode}', Description: '{$itemDescription}', Reason: '{$reason}'");
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

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        Log::debug('POSMasterfileImport: Processing raw row data: ' . json_encode($row));
        // NEW DEBUG: Log all available keys from the row to help identify correct header name
        Log::debug('POSMasterfileImport: Available row keys: ' . implode(', ', array_keys($row)));


        // Robustly get ItemCode, checking for common header variations
        // Ensure you use the exact header name from your Excel file here if none of these work.
        $itemCode = (string) ($row['item_code'] ?? $row['Item Code'] ?? $row['ItemCode'] ?? null);
        $itemDescription = (string) ($row['item_description'] ?? $row['Item Description'] ?? $row['ItemDescription'] ?? null);
        $category = (string) ($row['category'] ?? $row['Category'] ?? null);
        $subCategory = (string) ($row['subcategory'] ?? $row['SubCategory'] ?? null);
        $srp = (float) str_replace(',', '', ($row['srp'] ?? $row['SRP'] ?? 0));
        $isActive = (int) ($row['active'] ?? $row['Active'] ?? 1); // Default to 1 if not provided

        // Trim whitespace from itemCode and check if it's empty
        if (empty(trim($itemCode))) {
            $this->addSkippedItem($itemCode, $itemDescription, 'Item Code is missing or empty.');
            Log::warning("POSMasterfileImport: Skipping row due to blank Item Code after robust check: " . json_encode($row));
            return null; // Skip this row
        }

        $posMasterfile = new POSMasterfile([
            'ItemCode' => $itemCode,
            'ItemDescription' => $itemDescription,
            'Category' => $category,
            'SubCategory' => $subCategory,
            'SRP' => $srp,
            'is_active' => $isActive,
        ]);

        Log::debug('POSMasterfileImport: Created model for row: ' . json_encode($posMasterfile->toArray()));

        // Return the model instance. Maatwebsite\Excel with WithUpserts will handle insert/update.
        return $posMasterfile;
    }

    /**
     * Define the column(s) that should be used to uniquely identify a row.
     * This is used by the WithUpserts trait to determine if a record should be updated or inserted.
     *
     * @return string|array
     */
    public function uniqueBy()
    {
        return 'ItemCode';
    }

    /**
     * Define the batch size for batch inserts.
     * This improves performance by inserting multiple rows at once.
     * @return int
     */
    public function batchSize(): int
    {
        return 200;
    }

    /**
     * Define the chunk size for chunk reading.
     * This helps process very large files without running out of memory.
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000;
    }
}
