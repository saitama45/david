<?php

namespace App\Imports;

use App\Http\Services\SapItemTypeService;
use App\Models\SAPMasterfile;
use App\Support\EntityContext;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SAPMasterfileImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedItems = [];
    protected $processedCount = 0;
    protected $skippedCount = 0;
    protected static $seenCombinations = [];

    /** Item codes the downloadable template uses for its example rows. Never imported. */
    public const SAMPLE_PREFIX = 'SAMPLE-';

    /** BaseUOMs accepted for each ItemCode so far in this import, upper-cased. */
    protected static array $baseUomByItem = [];

    /** AltUOMs accepted for each ItemCode so far in this import: ItemCode => [ALTUOM => true]. */
    protected static array $packsByItem = [];

    /** Item Type accepted for each ItemCode so far in this import: ItemCode => type name. */
    protected static array $typeByItem = [];

    /** Rows imported with a note, e.g. an Item Type that is not on the managed list. */
    protected $warnings = [];

    /** @var array<string, array{id: int, name: string, is_active: bool}>|null */
    protected ?array $itemTypes = null;

    protected int $entityId;

    /**
     * Bulk upsert bypasses Eloquent model events, so BelongsToEntity's `creating`
     * hook never fires and rows would be written with a NULL entity_id (invisible
     * to every scoped read). The active entity is captured here and stamped onto
     * each row explicitly.
     *
     * An unresolvable entity aborts the import instead of writing NULLs — the
     * caller (SAPMasterfileImportJob) records the failure on the ImportLog, so it
     * surfaces as a failed import rather than silently orphaned rows that a later
     * entity-carrying import then duplicates. See the matching guard in
     * SupplierItemsImport, and the 2026_07_24 backfill migration that had to
     * repair exactly this.
     */
    public function __construct(?int $entityId = null)
    {
        $entityId = $entityId ?? app(EntityContext::class)->id();

        if ($entityId === null) {
            throw new \RuntimeException(
                'No active entity for this import. SAP masterfile rows must belong to an entity.'
            );
        }

        $this->entityId = $entityId;
    }

    public static function resetSeenCombinations()
    {
        self::$seenCombinations = [];
        self::$baseUomByItem = [];
        self::$packsByItem = [];
        self::$typeByItem = [];
    }

    /**
     * An ItemCode has one base unit. sap_masterfiles carries a row per
     * ItemCode + AltUOM, all of them sharing that base, and stock, month end
     * counts and BOM deductions are all expressed in it.
     *
     * A file that brings an item in under a second base unit does not correct
     * the first - the upsert key is ItemCode + AltUOM, so it adds a parallel set
     * of rows. The store then counts the item twice, once per base, while the
     * ledger only ever adjusts one of them. That is how 647A2A ended up as both
     * PC and LIT on 2025-11-28, and it is not recoverable by arithmetic: there
     * is no conversion between the two bases.
     *
     * So the row is refused rather than the file: the upload still completes and
     * the conflict lands on the skipped-items report for someone to settle in
     * SAP. Items already holding several bases are left alone - the row is
     * allowed if it matches any of them, so existing rows stay maintainable.
     *
     * @param  array<int, string>  $established  upper-cased bases already on file
     */
    public static function conflictingBaseUom(string $baseUom, array $established): bool
    {
        $baseUom = strtoupper(trim($baseUom));

        return $baseUom !== '' && $established !== [] && ! in_array($baseUom, $established, true);
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

            // A template uploaded without deleting its example rows must not add
            // dummy items to the masterfile.
            if (Str::startsWith(strtoupper($itemCode), self::SAMPLE_PREFIX)) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '', 'Sample row from the template; not imported.');
                $this->skippedCount++;
                continue;
            }

            if (empty($altUOM)) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '', 'AltUOM is missing or empty.');
                $this->skippedCount++;
                continue;
            }

            // SAP extracts restate a pack in a second base unit (Case = 48 Can, then
            // Case = 18720 Gm). Those are different rows, not copies, so BaseUOM is
            // part of the key.
            $baseKey = strtoupper(trim((string) ($row['baseuom'] ?? $row['BaseUOM'] ?? '')));
            $combination = $itemCode . '_' . $altUOM . '_' . $baseKey;

            if (isset(self::$seenCombinations[$combination])) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '', 'Duplicate item within the import file. Only the first occurrence was processed.');
                $this->skippedCount++;
                continue;
            }

            self::$seenCombinations[$combination] = true;
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
        $pendingTypes = [];
        $imported = [];
        $knownBaseUoms = $this->baseUomsOnFile(array_unique($itemCodesInChunk));

        // 2. Prepare data for bulk upsert
        foreach ($validRows as $validRow) {
            $itemCode = $validRow['itemCode'];
            $altUOM = $validRow['altUOM'];
            $row = $validRow['row'];
            $baseUOM = (string) ($row['baseuom'] ?? $row['BaseUOM'] ?? null);

            // Earlier rows of this same file count as established too, so a file
            // that contradicts itself is caught on its second base unit.
            $established = array_values(array_unique(array_merge(
                $knownBaseUoms[$itemCode] ?? [],
                self::$baseUomByItem[$itemCode] ?? []
            )));
            $packKey = strtoupper($altUOM);

            // A pack this file already gave under the accepted base, restated in
            // another unit (Case = 48 Can, then Case = 18720 Gm), is a conversion SAP
            // carries, not a changed base - it and its base row are let in.
            if (self::conflictingBaseUom($baseUOM, $established) && ! isset(self::$packsByItem[$itemCode][$packKey])) {
                $this->addSkippedItem($itemCode, $altUOM, $row['item_description'] ?? '',
                    "BaseUOM '".trim($baseUOM)."' conflicts with '".implode("' / '", $established)
                    ."' already on file for this item. Correct the base unit in SAP; importing it would count the item twice.");
                $this->skippedCount++;
                continue;
            }

            self::$packsByItem[$itemCode][$packKey] = true;
            $baseKey = strtoupper(trim($baseUOM));
            if ($baseKey !== '' && ! in_array($baseKey, self::$baseUomByItem[$itemCode] ?? [], true)) {
                self::$baseUomByItem[$itemCode][] = $baseKey;
            }

            if (($typeId = $this->itemTypeFor($itemCode, $altUOM, $row)) !== null) {
                $pendingTypes[$itemCode] = $typeId;
            }

            $upsertData[] = [
                'ItemCode' => $itemCode,
                'AltUOM' => $altUOM,
                'ItemDescription' => (string) ($row['item_description'] ?? $row['Item Description'] ?? $row['ItemDescription'] ?? null),
                'AltQty' => (float) ($row['altqty'] ?? 1),
                'BaseQty' => (float) ($row['baseqty'] ?? 0),
                'BaseUOM' => $baseUOM,
                'is_active' => (int) ($row['active'] ?? $row['Active'] ?? 1),
                'entity_id' => $this->entityId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Match per entity so one entity's import cannot overwrite another's row for
        // the same ItemCode/AltUOM. entity_id is always set (the constructor refuses
        // to run without one), which keeps the MERGE's `=` comparison matchable — a
        // NULL here would never match and would insert a duplicate on every
        // re-import. BaseUOM is matched too, since one pack can carry a row per base.
        //
        // A pair stored with a blank BaseUOM is still matched without it, so its
        // row is filled in rather than joined by a second one. Only the file's first
        // row for that pair goes that way; two in one MERGE would hit the same row.
        $blankPairs = $this->pairsWithBlankBaseUom(array_unique($itemCodesInChunk));
        $groups = ['pair' => [], 'base' => []];
        foreach ($upsertData as $data) {
            $pair = $data['ItemCode'].'_'.strtoupper($data['AltUOM']);
            if (isset($blankPairs[$pair])) {
                unset($blankPairs[$pair]);
                $groups['pair'][] = $data;
            } else {
                $groups['base'][] = $data;
            }
        }

        foreach ($groups as $group => $groupRows) {
            $this->upsertRows($groupRows, $group === 'pair'
                ? ['ItemCode', 'AltUOM', 'entity_id']
                : ['ItemCode', 'AltUOM', 'BaseUOM', 'entity_id'], $imported);
        }

        // Only items whose SAP rows made it in get their type.
        $this->assignItemTypes(array_intersect_key($pendingTypes, $imported), $now);
    }

    /**
     * @param  array<int, array<string, mixed>>  $upsertData
     * @param  array<int, string>  $matchColumns
     * @param  array<string, bool>  $imported  ItemCodes that made it in, filled here
     */
    protected function upsertRows(array $upsertData, array $matchColumns, array &$imported): void
    {
        // 3. Bulk Upsert (Batch processing)
        // Batch size set to 100 to prevent exceeding SQL Server's 2100 parameter limit
        $upsertBatchSize = 100;
        $chunks = array_chunk($upsertData, $upsertBatchSize);

        foreach ($chunks as $chunk) {
            try {
                // Attempt to upsert the chunk
                SAPMasterfile::upsert(
                    $chunk,
                    $matchColumns, // Unique columns for match
                    ['ItemDescription', 'AltQty', 'BaseQty', 'BaseUOM', 'is_active', 'updated_at'] // Columns to update
                );
                $this->processedCount += count($chunk);
                foreach ($chunk as $data) {
                    $imported[$data['ItemCode']] = true;
                }
            } catch (\Exception $e) {
                Log::warning("SAPMasterfile Import Bulk Upsert failed for a chunk, falling back to row-by-row. Error: " . $e->getMessage());
                
                // 4. Fallback to row-by-row for accurate error reporting
                foreach ($chunk as $data) {
                    try {
                        SAPMasterfile::upsert(
                            [$data],
                            $matchColumns,
                            ['ItemDescription', 'AltQty', 'BaseQty', 'BaseUOM', 'is_active', 'updated_at']
                        );
                        $this->processedCount++;
                        $imported[$data['ItemCode']] = true;
                    } catch (\Exception $innerE) {
                        $this->addSkippedItem($data['ItemCode'], $data['AltUOM'], $data['ItemDescription'] ?? '', 'Error processing row: ' . $innerE->getMessage());
                        $this->skippedCount++;
                        Log::error("Error processing SAPMasterfile row: " . $innerE->getMessage());
                    }
                }
            }
        }
    }

    /**
     * ItemCode + AltUOM pairs stored with a blank BaseUOM, keyed "ItemCode_ALTUOM".
     *
     * @param  array<int, string>  $itemCodes
     * @return array<string, bool>
     */
    protected function pairsWithBlankBaseUom(array $itemCodes): array
    {
        if (! $itemCodes) {
            return [];
        }

        return DB::table('sap_masterfiles')
            ->where('entity_id', $this->entityId)
            ->whereIn('ItemCode', $itemCodes)
            ->where(fn ($q) => $q->whereNull('BaseUOM')->orWhere('BaseUOM', ''))
            ->get(['ItemCode', 'AltUOM'])
            ->mapWithKeys(fn ($r) => [$r->ItemCode.'_'.strtoupper(trim((string) $r->AltUOM)) => true])
            ->all();
    }

    /**
     * The Item Type id this row sets, or null when it sets none.
     *
     * A blank or missing Item Type leaves the item's type alone, so an ordinary
     * SAP extract (which has no such column) never clears anyone's types. An
     * unknown or deactivated name is not created - that is how free-text lists
     * fill up with near-duplicates - the SAP row still imports, with a warning.
     */
    protected function itemTypeFor(string $itemCode, string $altUOM, array $row): ?int
    {
        $name = SapItemTypeService::normalize($row['item_type'] ?? null);
        if ($name === '') {
            return null;
        }

        $description = $row['item_description'] ?? '';
        $accepted = self::$typeByItem[$itemCode] ?? null;
        if ($accepted !== null) {
            if ($accepted !== $name) {
                $this->addWarning($itemCode, $altUOM, $description,
                    "Item Type '{$name}' ignored; this file already gave this item code '{$accepted}'.");
            }

            return null;
        }

        $this->itemTypes ??= app(SapItemTypeService::class)->nameMap($this->entityId);
        $type = $this->itemTypes[$name] ?? null;
        self::$typeByItem[$itemCode] = $name;

        if (! $type || ! $type['is_active']) {
            $this->addWarning($itemCode, $altUOM, $description, $type
                ? "Item Type '{$name}' is deactivated; the item's type was left unchanged."
                : "Item Type '{$name}' is not on the managed list; the item's type was left unchanged.");

            return null;
        }

        return $type['id'];
    }

    /** @param  array<string, int>  $types  ItemCode => type id */
    protected function assignItemTypes(array $types, Carbon $now): void
    {
        if (! $types) {
            return;
        }

        $current = DB::table('sap_item_type_assignments')
            ->where('entity_id', $this->entityId)
            // Numeric item codes become int array keys; an int parameter against this
            // nvarchar column makes SQL Server convert every row and fail on 'ABC'.
            ->whereIn('item_code', array_map('strval', array_keys($types)))
            ->pluck('sap_item_type_id', 'item_code');

        $rows = [];
        foreach ($types as $itemCode => $typeId) {
            if (isset($current[$itemCode]) && (int) $current[$itemCode] === $typeId) {
                continue;
            }
            $rows[] = ['entity_id' => $this->entityId, 'item_code' => (string) $itemCode,
                'sap_item_type_id' => $typeId, 'created_at' => $now, 'updated_at' => $now];
        }

        // 5 columns a row keeps a batch of 300 under SQL Server's 2,100-parameter limit.
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('sap_item_type_assignments')
                ->upsert($chunk, ['entity_id', 'item_code'], ['sap_item_type_id', 'updated_at']);
        }
    }

    /**
     * Base units already recorded for these items, upper-cased, per ItemCode.
     *
     * @param  array<int, string>  $itemCodes
     * @return array<string, array<int, string>>
     */
    protected function baseUomsOnFile(array $itemCodes): array
    {
        if (! $itemCodes) {
            return [];
        }

        return collect(\Illuminate\Support\Facades\DB::table('sap_masterfiles')
            ->where('entity_id', $this->entityId)
            ->whereIn('ItemCode', $itemCodes)
            ->select('ItemCode', 'BaseUOM')->distinct()->get())
            ->groupBy('ItemCode')
            ->map(fn ($rows) => $rows->pluck('BaseUOM')
                ->map(fn ($uom) => strtoupper(trim((string) $uom)))
                ->filter()->unique()->values()->all())
            ->all();
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

    protected function addWarning(?string $itemCode, ?string $altUOM, ?string $itemDescription, string $reason): void
    {
        $this->warnings[] = [
            'item_code' => $itemCode,
            'alt_uom' => $altUOM,
            'item_description' => $itemDescription,
            'reason' => $reason,
        ];
    }

    /** Rows that imported but need attention. Not counted as skipped. */
    public function getWarnings(): array
    {
        return $this->warnings;
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
