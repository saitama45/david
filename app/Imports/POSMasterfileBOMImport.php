<?php

namespace App\Imports;

use App\Models\POSMasterfileBOM;
use App\Models\POSMasterfile;
use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class POSMasterfileBOMImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedItems = [];
    protected $processedCount = 0;
    protected $skippedCount = 0;
    protected $emptyCount = 0;
    protected static $seenCombinations = [];

    public static function resetSeenCombinations()
    {
        self::$seenCombinations = [];
    }

    public function collection(Collection $rows)
    {
        $toFloat = fn($value) => is_numeric($value) ? (float)$value : (float)str_replace(',', '', (string)$value);

        foreach ($rows as $row) {
            $posCode = null;
            $itemCode = null;
            $assembly = null;
            $bomUOM = null; // Declare early

            try {
                // If the row is completely empty, skip it silently.
                if ($row instanceof Collection && $row->filter(fn($val) => !is_null($val) && trim((string) $val) !== '')->isEmpty()) {
                    $this->emptyCount++;
                    continue;
                }

                // Robustly get key fields
                $posCode = (string) Str::of($row['pos_code'] ?? $row['POS Code'] ?? null)->trim();
                $itemCode = (string) Str::of($row['item_code'] ?? $row['ITEM CODE'] ?? null)->trim();
                $assembly = (string) Str::of($row['assembly'] ?? $row['ASSEMBLY'] ?? null)->trim();
                $bomUOM = (string) Str::of($row['bom_uom'] ?? $row['BOM UOM'] ?? null)->trim(); // Get BOM UOM early for validation
                $bomQtyRaw = $row['bom_qty'] ?? $row['BOM QTY'] ?? 0; // Get raw BOM Qty for validation

                // --- Start Validation ---
                if (empty($posCode) || empty($itemCode)) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'POS Code or Item Code is missing.');
                    $this->skippedCount++;
                    continue;
                }
                
                if (!POSMasterfile::where('POSCode', $posCode)->exists()) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'POS Code not found in POS Masterfile.');
                    $this->skippedCount++;
                    continue;
                }

                if (!SAPMasterfile::where('ItemCode', $itemCode)->exists()) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'Item Code not found in SAP Masterfile.');
                    $this->skippedCount++;
                    continue;
                }

                // Validate BOM Qty must be > 0
                $bomQty = $toFloat($bomQtyRaw);
                if ($bomQty <= 0) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'BOM Qty must be greater than 0.');
                    $this->skippedCount++;
                    continue;
                }

                // Duplicate check using POS Code, Assembly, Item Code, and BOM UOM
                $combination = strtolower("{$posCode}_{$assembly}_{$itemCode}_{$bomUOM}");
                if (in_array($combination, self::$seenCombinations)) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'Duplicate entry (POS Code, Assembly, Item Code, BOM UOM) within the import file.');
                    $this->skippedCount++;
                    continue;
                }
                self::$seenCombinations[] = $combination;
                // --- End Validation ---

                // Robustly get other fields
                $posDescription = (string) Str::of($row['pos_description'] ?? $row['POS Description'] ?? null)->trim();
                $itemDescription = (string) Str::of($row['item_description'] ?? $row['PRODUCT DESCRIPTION'] ?? null)->trim();
                $recPercent = $toFloat($row['rec_percent'] ?? $row['REC%'] ?? 0);
                $recipeQty = $toFloat($row['recipe_qty'] ?? $row['RECIPE QTY'] ?? 0);
                $recipeUOM = (string)Str::of($row['recipe_uom'] ?? $row['RECIPE UOM'] ?? null)->trim();
                $unitCost = $toFloat($row['unit_cost'] ?? $row['UNIT COST'] ?? 0);
                $totalCost = $toFloat($row['total_cost'] ?? $row['TOTAL COST'] ?? 0);

                // Define attributes to find the record (unique key for the database)
                $attributes = [
                    'POSCode' => $posCode,
                    'ItemCode' => $itemCode,
                    'BOMUOM' => $bomUOM,
                    'Assembly' => $assembly,
                ];

                // Define values to be updated or created (all other columns)
                $values = [
                    'POSDescription' => $posDescription,
                    'ItemDescription' => $itemDescription,
                    'RecPercent' => $recPercent,
                    'RecipeQty' => $recipeQty,
                    'RecipeUOM' => $recipeUOM,
                    'BOMQty' => $bomQty,
                    'UnitCost' => $unitCost,
                    'TotalCost' => $totalCost,
                    'updated_by' => Auth::id(), // Always update updated_by
                ];
                
                $posMasterfileBOM = POSMasterfileBOM::firstOrNew($attributes);
                
                // Only set created_by if the record is new
                if (!$posMasterfileBOM->exists) {
                    $posMasterfileBOM->created_by = Auth::id();
                }

                $posMasterfileBOM->fill($values)->save();

                $this->processedCount++;
            } catch (\Exception $e) {
                $this->addSkippedItem($posCode, $itemCode, $assembly, 'Error processing row: ' . $e->getMessage());
                $this->skippedCount++;
                Log::error("Error processing POSMasterfileBOM row: " . $e->getMessage(), ['row' => $row->toArray()]);
            }
        }
    }

    protected function addSkippedItem(?string $posCode, ?string $itemCode, ?string $assembly, string $reason): void
    {
        $this->skippedItems[] = [
            'pos_code' => $posCode,
            'item_code' => $itemCode,
            'assembly' => $assembly,
            'reason' => $reason,
        ];
        Log::warning("POSMasterfileBOMImport: Skipped item - POS Code: '{$posCode}', Item Code: '{$itemCode}', Assembly: '{$assembly}', Reason: '{$reason}'");
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

    public function getEmptyCount(): int
    {
        return $this->emptyCount;
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}