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

        // 2. Preload existing records to avoid N+1 queries
        $existingRecords = SAPMasterfile::whereIn('ItemCode', array_unique($itemCodesInChunk))
            ->get()
            ->keyBy(function ($item) {
                return $item->ItemCode . '_' . $item->AltUOM;
            });

        $inserts = [];
        $now = Carbon::now();

        // 3. Process valid rows: Update existing or prepare for bulk insert
        foreach ($validRows as $validRow) {
            try {
                $itemCode = $validRow['itemCode'];
                $altUOM = $validRow['altUOM'];
                $row = $validRow['row'];
                $combination = $validRow['combination'];

                $data = [
                    'ItemDescription' => (string) ($row['item_description'] ?? $row['Item Description'] ?? $row['ItemDescription'] ?? null),
                    'AltQty' => (float) ($row['altqty'] ?? 1),
                    'BaseQty' => (float) ($row['baseqty'] ?? 0),
                    'BaseUOM' => (string) ($row['baseuom'] ?? $row['BaseUOM'] ?? null),
                    'is_active' => (int) ($row['active'] ?? $row['Active'] ?? 1),
                ];

                if ($existingRecords->has($combination)) {
                    // Update existing record
                    // This still fires 1 update query per existing row, but saves 1 SELECT query per row
                    $record = $existingRecords->get($combination);
                    $record->update($data);
                } else {
                    // Prepare for bulk insert
                    $data['ItemCode'] = $itemCode;
                    $data['AltUOM'] = $altUOM;
                    $data['created_at'] = $now;
                    $data['updated_at'] = $now;
                    $inserts[] = $data;
                }

                $this->processedCount++;
                
            } catch (\Exception $e) {
                $this->addSkippedItem($validRow['itemCode'], $validRow['altUOM'], $validRow['row']['item_description'] ?? '', 'Error processing row: ' . $e->getMessage());
                $this->skippedCount++;
                // If it fails, rollback the processed count for this item
                $this->processedCount--;
                Log::error("Error processing SAPMasterfile row: " . $e->getMessage());
            }
        }

        // 4. Bulk insert new records
        if (!empty($inserts)) {
            try {
                // Insert in smaller chunks to avoid parameter limits on DB
                foreach (array_chunk($inserts, 500) as $chunk) {
                    SAPMasterfile::insert($chunk);
                }
            } catch (\Exception $e) {
                Log::error("SAPMasterfile Import Bulk Insert Error: " . $e->getMessage());
                // Handle bulk insert failure (could add all to skipped items, but this is a critical error)
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
        // Increased chunk size to 1000 for better batch processing performance
        return 1000;
    }
}
