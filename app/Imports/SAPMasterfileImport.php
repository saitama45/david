<?php

namespace App\Imports;

use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        foreach ($rows as $row) {
            try {
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

                // Using updateOrCreate to update existing records or create new ones.
                SAPMasterfile::updateOrCreate(
                    [
                        'ItemCode' => $itemCode,
                        'AltUOM' => $altUOM
                    ],
                    [
                        'ItemDescription' => (string) ($row['item_description'] ?? $row['Item Description'] ?? $row['ItemDescription'] ?? null),
                        'AltQty' => (float) ($row['altqty'] ?? 0),
                        'BaseQty' => (float) ($row['baseqty'] ?? 0),
                        'BaseUOM' => (string) ($row['baseuom'] ?? $row['BaseUOM'] ?? null),
                        'is_active' => (int) ($row['active'] ?? $row['Active'] ?? 1),
                    ]
                );

                $this->processedCount++;
                
            } catch (\Exception $e) {
                $this->addSkippedItem($itemCode ?? '', $altUOM ?? '', $row['item_description'] ?? '', 'Error processing row: ' . $e->getMessage());
                $this->skippedCount++;
                Log::error("Error processing SAPMasterfile row: " . $e->getMessage());
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
        return 100;
    }
}