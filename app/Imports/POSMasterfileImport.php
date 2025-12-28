<?php

namespace App\Imports;

use App\Models\POSMasterfile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class POSMasterfileImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedItems = [];
    protected $processedCount = 0;
    protected $skippedCount = 0;
    protected static $seenCombinations = [];

    public static function resetSeenCombinations()
    {
        self::$seenCombinations = [];
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            try {
                // If the row is completely empty, skip it silently.
                if ($row instanceof Collection && $row->filter()->isEmpty()) {
                    continue;
                }

                // Robustly get the POS code and description, trying multiple possible header formats.
                $posCode = (string) Str::of($row['product_id'] ?? $row['Product ID'] ?? $row['item_code'] ?? $row['Item Code'] ?? $row['pos_code'] ?? null)->trim();
                $posDescription = (string) Str::of($row['pos_desc'] ?? $row['POS Desc'] ?? $row['pos_name'] ?? $row['POS Name'] ?? $row['item_description'] ?? $row['Item Description'] ?? $row['pos_description'] ?? null)->trim();

                // If even after checking multiple keys, the code is empty, we log it as a skipped item.
                if (empty($posCode)) {
                    // Check if the row was just empty space or similar, and if so, skip silently.
                    if ($row instanceof Collection && $row->filter(fn($val) => !is_null($val) && trim((string) $val) !== '')->isEmpty()) {
                        continue;
                    }
                    $this->addSkippedItem(null, null, 'Product ID / Item Code is missing or empty in a non-empty row.');
                    $this->skippedCount++;
                    continue;
                }
                
                if (in_array($posCode, self::$seenCombinations)) {
                    $this->addSkippedItem($posCode, $posDescription, 'Duplicate item within the import file. Only the first occurrence was processed.');
                    $this->skippedCount++;
                    continue;
                }

                self::$seenCombinations[] = $posCode;

                // Robustly get other fields
                $category = (string) Str::of($row['category'] ?? $row['Category'] ?? null)->trim();
                $subCategory = (string) Str::of($row['subcategory'] ?? $row['SubCategory'] ?? $row['sub_category'] ?? null)->trim();
                
                // For prices, check multiple header variations and handle surrounding whitespace in headers like ' SRP '
                $srp = $row['srp'] ?? $row[' SRP '] ?? 0;
                
                $toFloat = fn($value) => is_numeric($value) ? (float)$value : (float)str_replace(',', '', (string)$value);

                POSMasterfile::updateOrCreate(
                    ['POSCode' => $posCode],
                    [
                        'POSDescription' => $posDescription,
                        'Category' => $category,
                        'SubCategory' => $subCategory,
                        'SRP' => $toFloat($srp),
                        'is_active' => filter_var($row['active'] ?? $row['Active'] ?? 1, FILTER_VALIDATE_BOOLEAN),
                    ]
                );

                $this->processedCount++;

            } catch (\Exception $e) {
                // Try to get a pos code for the error log, even if it failed before
                $errorCode = (string) Str::of($row['item_code'] ?? $row['Item Code'] ?? $row['pos_code'] ?? 'N/A')->trim();
                $this->addSkippedItem($errorCode, null, 'Error processing row: ' . $e->getMessage());
                $this->skippedCount++;
                Log::error("Error processing POSMasterfile row: " . $e->getMessage(), ['row' => $row->toArray()]);
            }
        }
    }

    protected function addSkippedItem(?string $posCode, ?string $posDescription, string $reason): void
    {
        $this->skippedItems[] = [
            'pos_code' => $posCode,
            'pos_description' => $posDescription,
            'reason' => $reason,
        ];
        Log::warning("POSMasterfileImport: Skipped item - POS Code: '{$posCode}', Description: '{$posDescription}', Reason: '{$reason}'");
    }

    public function getSkippedItems(): array
    {
        return $this->skippedItems;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function chunkSize(): int
    {
        return 100;
    }
}