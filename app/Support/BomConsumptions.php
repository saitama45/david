<?php

namespace App\Support;

use App\Models\Wastage;
use Illuminate\Support\Facades\DB;

/**
 * What a sale or a wasted Sub-Prep used of its raw materials, frozen when its stock is posted.
 *
 * The Inventory Movement Report (and the Qty / Cost Variance report and the Month End Count
 * template, which read it) sums these lines instead of joining today's BOM, so editing a recipe
 * later never moves a past figure. A sale or wastage without lines (posted before they existed
 * and not yet frozen by `bom:freeze-history`) still reads the BOM as before.
 */
final class BomConsumptions
{
    public const SALE = 'sale';

    public const WASTAGE = 'wastage';

    /** Written by the posting itself. */
    public const POSTED = 'posted';

    /** Frozen afterwards from the sale's own posting record (what was really deducted). */
    public const SNAPSHOT = 'snapshot';

    /** Frozen afterwards from the BOM as it stood on that day: nothing older was kept. */
    public const CURRENT_BOM = 'current_bom';

    /**
     * One line. Keys: entity_id, store_branch_id, business_date, source_type, origin, item_code,
     * bom_uom, bom_qty, units, qty; optional: store_transaction_id, store_transaction_item_id,
     * wastage_id, pos_masterfile_id, pos_code, pos_description, bom_line_id, stock_row_id,
     * stock_qty, unit_cost, movement_id.
     *
     * @param  array<string, mixed>  $line
     */
    public static function record(array $line): void
    {
        DB::table('bom_consumptions')->insert(self::row($line));
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function row(array $line): array
    {
        $now = now();

        return [
            'entity_id' => $line['entity_id'] ?? null,
            'store_branch_id' => $line['store_branch_id'],
            'business_date' => $line['business_date'],
            'source_type' => $line['source_type'],
            'store_transaction_id' => $line['store_transaction_id'] ?? null,
            'store_transaction_item_id' => $line['store_transaction_item_id'] ?? null,
            'wastage_id' => $line['wastage_id'] ?? null,
            'pos_masterfile_id' => $line['pos_masterfile_id'] ?? null,
            'pos_code' => $line['pos_code'] ?? null,
            'pos_description' => isset($line['pos_description']) ? mb_substr((string) $line['pos_description'], 0, 255) : null,
            'bom_line_id' => $line['bom_line_id'] ?? null,
            'item_code' => (string) $line['item_code'],
            'bom_uom' => isset($line['bom_uom']) ? trim((string) $line['bom_uom']) : null,
            // Fixed-point text: SQL Server rejects a float sent as 5.3E-5 to a decimal column.
            'bom_qty' => self::decimal($line['bom_qty'], 7),
            'units' => self::decimal($line['units'], 4),
            'qty' => self::decimal($line['qty'], 10),
            'stock_row_id' => $line['stock_row_id'] ?? null,
            'stock_qty' => isset($line['stock_qty']) ? self::decimal($line['stock_qty'], 10) : null,
            'unit_cost' => isset($line['unit_cost']) ? self::decimal($line['unit_cost'], 10) : null,
            'movement_id' => $line['movement_id'] ?? null,
            'origin' => $line['origin'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * The lines of one approved Sub-Prep wastage: its BOM read now (at approval, or by the
     * backfill), for the quantity approved at Level 2, which is what the report sums. A Scrap
     * line moves no stock but is still in the report's wastage figure, so it gets lines too.
     *
     * @param  array<int|string, int>  $movementIds  stock row id => the approval's stock movement id
     */
    public static function recordSubPrepWastage(Wastage $wastage, string $origin, array $movementIds = []): int
    {
        $subPrep = $wastage->posMasterfile;

        if ($wastage->sap_masterfile_id !== null || $subPrep === null) {
            return 0;
        }

        $units = (float) ($wastage->approverlvl2_qty ?? 0);
        // The date the report files a wastage under: the day it was created, as stored.
        $date = substr((string) $wastage->getRawOriginal('created_at'), 0, 10);
        $entityId = DB::table('store_branches')->where('id', $wastage->store_branch_id)->value('entity_id');
        $count = 0;

        foreach (SubPrepRecipe::ingredients($subPrep) as $ingredient) {
            $qty = $ingredient['bom_qty'] * $units;
            $stockQty = $ingredient['factor'] ? $qty * $ingredient['factor'] : null;
            $stockRowId = $ingredient['stock_row']?->id;

            self::record([
                'entity_id' => $entityId,
                'store_branch_id' => $wastage->store_branch_id,
                'business_date' => $date,
                'source_type' => self::WASTAGE,
                'wastage_id' => $wastage->id,
                'pos_masterfile_id' => $subPrep->id,
                'pos_code' => $subPrep->POSCode,
                'pos_description' => $subPrep->POSDescription,
                'item_code' => $ingredient['item_code'],
                'bom_uom' => $ingredient['bom_uom'],
                'bom_qty' => $ingredient['bom_qty'],
                'units' => $units,
                'qty' => $qty,
                'stock_row_id' => $stockRowId,
                'stock_qty' => $stockQty,
                // The BOM cost of one stock unit: cost is per one unit of the Sub-Prep.
                'unit_cost' => $stockQty ? abs($ingredient['cost'] / ($ingredient['bom_qty'] * $ingredient['factor'])) : null,
                'movement_id' => $stockRowId !== null ? ($movementIds[$stockRowId] ?? null) : null,
                'origin' => $origin,
            ]);
            $count++;
        }

        // Frozen even with no lines: the reports never read today's BOM for it again.
        DB::table('wastages')->where('id', $wastage->id)->update(['bom_frozen_at' => now()]);

        return $count;
    }

    private static function decimal(mixed $value, int $places): string
    {
        return number_format((float) $value, $places, '.', '');
    }
}
