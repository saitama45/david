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
     * @param string|null $posCode
     * @param string|null $posDescription
     * @param string $reason
     * @return void
     */
    // CRITICAL FIX: Changed parameters to posCode and posDescription for clarity and consistency
    protected function addSkippedItem(?string $posCode, ?string $posDescription, string $reason): void
    {
        $this->skippedItems[] = [
            'pos_code' => $posCode, // Changed from item_code
            'pos_description' => $posDescription, // Changed from item_description
            'reason' => $reason,
        ];
        Log::warning("POSMasterfileImport: Skipped item - POS Code: '{$posCode}', Description: '{$posDescription}', Reason: '{$reason}'");
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


        // Robustly get POSCode and POSDescription, checking for common header variations
        // CRITICAL FIX: Changed variable names and checked keys to match new column names
        $posCode = (string) ($row['pos_code'] ?? $row['POS Code'] ?? $row['POSCode'] ?? $row['item_code'] ?? $row['Item Code'] ?? $row['ItemCode'] ?? null);
        $posDescription = (string) ($row['pos_description'] ?? $row['POS Description'] ?? $row['POSDescription'] ?? $row['item_description'] ?? $row['Item Description'] ?? $row['ItemDescription'] ?? null);
        $category = (string) ($row['category'] ?? $row['Category'] ?? null);
        $subCategory = (string) ($row['subcategory'] ?? $row['SubCategory'] ?? null);
        $srp = (float) str_replace(',', '', ($row['srp'] ?? $row['SRP'] ?? 0));
        $deliveryPrice = (float) str_replace(',', '', ($row['delivery_price'] ?? $row['Delivery Price'] ?? $row['DeliveryPrice'] ?? 0));
        $tableVibePrice = (float) str_replace(',', '', ($row['table_vibe_price'] ?? $row['Table Vibe Price'] ?? $row['TableVibePrice'] ?? 0));
        $isActive = (int) ($row['active'] ?? $row['Active'] ?? 1); // Default to 1 if not provided

        // Trim whitespace from posCode and check if it's empty
        if (empty(trim($posCode))) {
            // CRITICAL FIX: Pass posCode and posDescription to addSkippedItem
            $this->addSkippedItem($posCode, $posDescription, 'POS Code is missing or empty.');
            Log::warning("POSMasterfileImport: Skipping row due to blank POS Code after robust check: " . json_encode($row));
            return null; // Skip this row
        }

        // CRITICAL FIX: Updated model instantiation to use POSCode and POSDescription
        $posMasterfile = new POSMasterfile([
            'POSCode' => $posCode,
            'POSDescription' => $posDescription,
            'Category' => $category,
            'SubCategory' => $subCategory,
            'SRP' => $srp,
            'DeliveryPrice' => $deliveryPrice,
            'TableVibePrice' => $tableVibePrice,
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
        return 'POSCode'; // CRITICAL FIX: Changed from ItemCode to POSCode
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
