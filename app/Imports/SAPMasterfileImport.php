<?php

namespace App\Imports;

use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SAPMasterfileImport implements ToCollection, WithHeadingRow, WithChunkReading
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
        $itemCodesInChunk = [];
        $validRows = [];

        // 1. Process rows and collect ItemCodes for preloading
        foreach ($rows as $row) {
            // Convert row to array if it's not
            if ($row instanceof Collection) {
                $row = $row->toArray();
            }

            // Robustly get ItemCode and AltUOM
            $itemCode = (string) Str::of($row['item_no'] ?? $row['item_code'] ?? $row['Item Code'] ?? $row['ItemCode'] ?? null)->trim();
            $altUOM = (string) Str::of($row['altuom'] ?? $row['AltUOM'] ?? null)->trim();

            // Check for required fields
            if (empty($itemCode)) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '', 'ItemCode is missing or empty.');
                $this->skippedCount++;
                continue;
            }

            if (empty($altUOM)) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '', 'AltUOM is missing or empty.');
                $this->skippedCount++;
                continue;
            }

            $combination = $itemCode . '_' . $altUOM;

            // Check for duplicates in the current import
            if (in_array($combination, self::$seenCombinations)) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '', 'Duplicate item within the import file. Only the first occurrence was processed.');
                $this->skippedCount++;
                continue;
            }

            // Add to seen combinations
            self::$seenCombinations[] = $combination;
            $itemCodesInChunk[] = $itemCode;
            
            $validRows[] = [
                'itemCode' => $itemCode,
                'altUOM' => $altUOM,
                'row' => $row,
                'combination' => $combination
            ];
        }

        if (empty($validRows)) {
            return; // Nothing to process in this chunk
        }

        $now = Carbon::now();
        $upsertData = [];

        // 2. Prepare data for bulk upsert
        foreach ($validRows as $validRow) {
            $itemCode = $validRow['itemCode'];
            $altUOM = $validRow['altUOM'];
            $row = $validRow['row'];

            $upsertData[] = [
                'ItemCode' => $itemCode,
                'AltUOM' => $altUOM,
                'ItemDescription' => (string) ($row['item_description'] ?? $row['Item Description'] ?? $row['ItemDescription'] ?? null),
                'AltQty' => (float) ($row['altqty'] ?? 1),
                'BaseQty' => (float) ($row['baseqty'] ?? 0),
                'BaseUOM' => (string) ($row['baseuom'] ?? $row['BaseUOM'] ?? null),
                'is_active' => (int) ($row['active'] ?? $row['Active'] ?? 1),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 3. Bulk Upsert (Batch processing)
        try {
            // Attempt to upsert the entire chunk
            // This turns up to 1000 queries into a single MERGE query on SQL Server
            SAPMasterfile::upsert(
                $upsertData,
                ['ItemCode', 'AltUOM'], // Unique columns for match
                ['ItemDescription', 'AltQty', 'BaseQty', 'BaseUOM', 'is_active', 'updated_at'] // Columns to update
            );
            $this->processedCount += count($upsertData);
        } catch (\Exception $e) {
            Log::warning("SAPMasterfile Import Bulk Upsert failed, falling back to row-by-row. Error: " . $e->getMessage());
            
            // 4. Fallback to row-by-row for accurate error reporting
            foreach ($upsertData as $data) {
                try {
                    SAPMasterfile::upsert(
                        [$data],
                        ['ItemCode', 'AltUOM'],
                        ['ItemDescription', 'AltQty', 'BaseQty', 'BaseUOM', 'is_active', 'updated_at']
                    );
                    $this->processedCount++;
                } catch (\Exception $innerE) {
                    $this->addSkippedItem($data['ItemCode'], $data['AltUOM'], $data['ItemDescription'] ?? '', 'Error processing row: ' . $innerE->getMessage());
                    $this->skippedCount++;
                    Log::error("Error processing SAPMasterfile row: " . $innerE->getMessage());
                }
            }
        }
    }

    protected function addSkippedItem(?string $itemCode, ?string $altUOM, ?string $itemDescription, string $reason): void
    {
        $this->skippedItems[] = [
            'item_code' => $itemCode,
            'alt_uom' => $altUOM,
            'item_description' => $itemDescription,
            'reason' => $reason,
        ];
        Log::warning("SAPMasterfileImport: Skipped item - Item Code: '{$itemCode}', AltUOM: '{$altUOM}', Description: '{$itemDescription}', Reason: '{$reason}'");
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
        // Reduced chunk size to 500 to keep parameter counts safely under SQL Server limits for batch upsert
        return 500;
    }
}
