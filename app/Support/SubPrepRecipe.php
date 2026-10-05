<?php

namespace App\Support;

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use Illuminate\Support\Collection;

/**
 * What one unit of a Sub-Prep uses of each raw material.
 *
 * The BOM is read the way a sale reads it (StoreTransactionReceiptProcessor): its BOM Qty
 * is for one unit of the POS item, so 5 ml of a mix whose BOM has 100 Gm of sugar used
 * 500 Gm of sugar.
 */
final class SubPrepRecipe
{
    /**
     * One entry per raw material + BOM unit, repeated lines added up:
     *   item_code, item_description, bom_qty, bom_uom - as the BOM spells them
     *   cost       - the BOM cost of that quantity (BOM Qty x Unit Cost)
     *   stock_row  - the SAP row the item's stock lives on; null when SAP has no such item or unit
     *   stock_unit - the unit of that row
     *   factor     - stock units per one BOM unit; null when the BOM unit does not convert
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function ingredients(POSMasterfile $subPrep): Collection
    {
        // The entity is the item's own, so a job with no entity bound reads the same BOM.
        $entityId = $subPrep->entity_id !== null ? (int) $subPrep->entity_id : null;
        $query = POSMasterfileBOM::query()->where('POSCode', $subPrep->POSCode)->orderBy('id');

        if ($entityId !== null) {
            $query->withoutEntityScope()->where('entity_id', $entityId);
        }

        return $query->get()
            ->filter(fn ($line) => (float) $line->BOMQty > 0)
            ->groupBy(fn ($line) => self::key($line->ItemCode) . '|' . self::key($line->BOMUOM))
            ->map(function (Collection $lines) use ($entityId) {
                $line = $lines->first();
                $stockUnit = ItemStockUnit::forItem($line->ItemCode, $entityId);

                return [
                    'item_code' => $line->ItemCode,
                    'item_description' => $line->ItemDescription,
                    'bom_qty' => (float) $lines->sum('BOMQty'),
                    'bom_uom' => trim((string) $line->BOMUOM),
                    'cost' => (float) $lines->sum(fn ($each) => (float) $each->BOMQty * (float) $each->UnitCost),
                    'stock_row' => $stockUnit->stockRowFor($line->BOMUOM),
                    'stock_unit' => $stockUnit->unitFor($line->BOMUOM),
                    'factor' => $stockUnit->factor($line->BOMUOM),
                ];
            })
            ->values();
    }

    private static function key(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
