<?php

namespace App\Support;

use App\Models\SupplierItems;
use Illuminate\Support\Collection;

/**
 * The supplier cost of one unit of an item. Supplier items are priced per unit
 * (Condense Milk: Can 37.50, Case 1800, Gm 0.10), so the unit is part of the key.
 */
final class SupplierUnitCost
{
    /** @param  Collection<string, Collection<string, float>>  $costs  ItemCode => UPPER unit => cost */
    private function __construct(private readonly Collection $costs) {}

    /** @param  iterable<string>  $itemCodes */
    public static function forItems(iterable $itemCodes): self
    {
        $costs = SupplierItems::whereIn('ItemCode', collect($itemCodes)->unique()->values()->all())
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['ItemCode', 'uom', 'cost'])
            ->groupBy('ItemCode')
            ->map(fn ($rows) => $rows->filter(fn ($row) => trim((string) $row->uom) !== '' && (float) $row->cost > 0)
                ->unique(fn ($row) => strtoupper(trim($row->uom)))
                ->mapWithKeys(fn ($row) => [strtoupper(trim($row->uom)) => (float) $row->cost]));

        return new self($costs);
    }

    /**
     * The unit's own supplier cost, else a linked unit's cost converted through the SAP
     * factors (1800 per Case = 37.50 per Can at 48 Can = 1 Case), else 1.0.
     */
    public function for(string $itemCode, ?ItemStockUnit $stockUnit, ?string $unit): float
    {
        $costsByUnit = $this->costs->get($itemCode, collect());
        $key = strtoupper(trim((string) $unit));

        if ($costsByUnit->has($key)) {
            return $costsByUnit->get($key);
        }

        $factor = $stockUnit?->factor($unit);
        $stockRow = $stockUnit?->stockRowFor($unit);

        if ($factor && $stockRow) {
            foreach ($costsByUnit as $otherUnit => $cost) {
                $otherFactor = $stockUnit->factor($otherUnit);
                if ($otherFactor && $stockUnit->stockRowFor($otherUnit)?->id === $stockRow->id) {
                    return round($cost * $factor / $otherFactor, 4);
                }
            }
        }

        return 1.0;
    }
}
