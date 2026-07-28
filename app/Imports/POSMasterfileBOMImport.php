<?php

namespace App\Imports;

use App\Models\POSMasterfile;
use App\Models\SAPMasterfile;
use App\Support\EntityContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class POSMasterfileBOMImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedItems = [];
    protected $processedCount = 0;
    protected $skippedCount = 0;
    protected $emptyCount = 0;
    protected static $seenCombinations = [];

    protected ?int $entityId;

    /**
     * Rows are written via the raw query builder (DB::table), which bypasses
     * Eloquent — so BelongsToEntity never stamps entity_id and inserts would land
     * NULL (invisible to every scoped read). The active entity is captured here
     * (the import runs synchronously in the web request, where the context IS set)
     * and applied to every existence check, update and insert below.
     */
    public function __construct(?int $entityId = null)
    {
        $this->entityId = $entityId ?? app(EntityContext::class)->id();
    }

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
            $bomUOM = null;

            try {
                if ($row instanceof Collection && $row->filter(fn($val) => !is_null($val) && trim((string) $val) !== '')->isEmpty()) {
                    $this->emptyCount++;
                    continue;
                }

                $posCode = (string) Str::of($row['pos_code'] ?? $row['POS Code'] ?? null)->trim();
                $itemCode = (string) Str::of($row['item_code'] ?? $row['ITEM CODE'] ?? null)->trim();
                $assembly = (string) Str::of($row['assembly'] ?? $row['ASSEMBLY'] ?? null)->trim();
                $bomUOM = (string) Str::of($row['bom_uom'] ?? $row['BOM UOM'] ?? null)->trim();
                $bomQtyRaw = $row['bom_qty'] ?? $row['BOM QTY'] ?? 0;

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

                $bomQty = $toFloat($bomQtyRaw);
                if ($bomQty <= 0) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'BOM Qty must be greater than 0.');
                    $this->skippedCount++;
                    continue;
                }

                $combination = strtolower("{$posCode}_{$itemCode}_{$assembly}_{$bomQty}");
                if (in_array($combination, self::$seenCombinations)) {
                    $this->addSkippedItem($posCode, $itemCode, $assembly, 'Duplicate entry (POS Code, Item Code, Assembly, BOM Qty) within the import file.');
                    $this->skippedCount++;
                    continue;
                }
                self::$seenCombinations[] = $combination;

                $posDescription = (string) Str::of($row['pos_description'] ?? $row['POS Description'] ?? null)->trim();
                $itemDescription = (string) Str::of($row['item_description'] ?? $row['PRODUCT DESCRIPTION'] ?? null)->trim();
                $recPercent = $toFloat($row['rec_percent'] ?? $row['REC%'] ?? 0);
                $recipeQty = $toFloat($row['recipe_qty'] ?? $row['RECIPE QTY'] ?? 0);
                $recipeUOM = (string)Str::of($row['recipe_uom'] ?? $row['RECIPE UOM'] ?? null)->trim();
                $unitCost = $toFloat($row['unit_cost'] ?? $row['UNIT COST'] ?? 0);
                $totalCost = $toFloat($row['total_cost'] ?? $row['TOTAL COST'] ?? 0);

                $attributes = [
                    'POSCode' => $posCode,
                    'ItemCode' => $itemCode,
                    'BOMUOM' => $bomUOM,
                    'Assembly' => $assembly,
                ];

                // Scope the raw existence check and update to the importing entity.
                // The write path is raw DB::table (no global scope), so without this
                // an update could match another entity's row for the same business
                // key, and the existence check must agree with what the update sees.
                $scoped = fn () => DB::table('pos_masterfiles_bom')
                    ->where($attributes)
                    ->when($this->entityId !== null, fn ($q) => $q->where('entity_id', $this->entityId))
                    ->when($this->entityId === null, fn ($q) => $q->whereNull('entity_id'));

                if ($scoped()->exists()) {
                    $scoped()->update([
                            'POSDescription' => $posDescription,
                            'ItemDescription' => $itemDescription,
                            'RecPercent' => number_format($recPercent, 4, '.', ''),
                            'RecipeQty' => number_format($recipeQty, 4, '.', ''),
                            'RecipeUOM' => $recipeUOM,
                            'BOMQty' => number_format($bomQty, 7, '.', ''),
                            'UnitCost' => number_format($unitCost, 4, '.', ''),
                            'TotalCost' => number_format($totalCost, 4, '.', ''),
                            'updated_by' => Auth::id(),
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('pos_masterfiles_bom')->insert([
                        'POSCode' => $posCode,
                        'ItemCode' => $itemCode,
                        'BOMUOM' => $bomUOM,
                        'Assembly' => $assembly,
                        'POSDescription' => $posDescription,
                        'ItemDescription' => $itemDescription,
                        'RecPercent' => number_format($recPercent, 4, '.', ''),
                        'RecipeQty' => number_format($recipeQty, 4, '.', ''),
                        'RecipeUOM' => $recipeUOM,
                        'BOMQty' => number_format($bomQty, 7, '.', ''),
                        'UnitCost' => number_format($unitCost, 4, '.', ''),
                        'TotalCost' => number_format($totalCost, 4, '.', ''),
                        'entity_id' => $this->entityId,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

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
