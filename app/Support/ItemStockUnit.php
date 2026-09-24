<?php

namespace App\Support;

use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;

/**
 * Which sap_masterfiles row an item's stock lives on, and how many of its unit any
 * other unit holds.
 *
 * SAP can restate an item in a second base (Condense Milk has Can/Can and Case/Case,
 * with 48 Can = 1 Case), so "the BaseUOM = AltUOM row" is ambiguous. Units SAP links by
 * conversion rows (AltQty x AltUOM = BaseQty x BaseUOM, chained) share one stock row:
 * the base row of the unit those conversions point at, the SAP base unit. A base unit
 * with no conversion to the others (Sprite's Can next to LIT) is a separate stock and
 * keeps its own base row.
 */
final class ItemStockUnit
{
    /**
     * @param  array<string, string>  $stockUnitOf  UPPER unit => UPPER stock unit of its group
     * @param  array<string, float>  $factors  stock units (of its group) per one UPPER unit
     * @param  array<string, object>  $baseRows  UPPER stock unit => its base row
     * @param  array<string, string>  $labels  UPPER unit => label as SAP spells it
     */
    private function __construct(
        private readonly ?string $primary,
        private readonly array $stockUnitOf,
        private readonly array $factors,
        private readonly array $baseRows,
        private readonly array $labels,
    ) {}

    public static function forItem(string $itemCode, ?int $entityId = null): self
    {
        $query = SAPMasterfile::query()->where('ItemCode', $itemCode)->orderBy('id');

        if ($entityId !== null) {
            $query->withoutEntityScope()->where('entity_id', $entityId);
        }

        return self::fromRows($query->get());
    }

    /**
     * @param  Collection<int, object>  $rows  every sap_masterfiles row of one item
     */
    public static function fromRows(Collection $rows): self
    {
        $labels = [];
        $edges = [];
        $baseCounts = [];
        $baseRows = [];

        foreach ($rows->sortBy('id') as $row) {
            $alt = self::key($row->AltUOM);
            $base = self::key($row->BaseUOM);

            foreach ([$alt => $row->AltUOM, $base => $row->BaseUOM] as $key => $label) {
                if ($key !== '') {
                    $labels[$key] ??= trim((string) $label);
                    $edges[$key] ??= [];
                }
            }

            if ($alt !== '' && $alt === $base) {
                $baseRows[$base] ??= $row;
                continue;
            }

            if ($alt === '' || $base === '' || (float) $row->AltQty <= 0 || (float) $row->BaseQty <= 0) {
                continue;
            }

            // One AltUOM holds BaseQty / AltQty of the BaseUOM.
            $factor = (float) $row->BaseQty / (float) $row->AltQty;
            $edges[$alt][$base] = $factor;
            $edges[$base][$alt] = 1 / $factor;
            $baseCounts[$base] = ($baseCounts[$base] ?? 0) + 1;
        }

        $stockUnitOf = [];
        $factors = [];
        $primary = null;
        $primaryScore = null;

        foreach (array_keys($edges) as $start) {
            if (isset($stockUnitOf[$start])) {
                continue;
            }

            // Collect the group of units SAP links to $start.
            $group = [$start];
            $seen = [$start => true];
            for ($i = 0; $i < count($group); $i++) {
                foreach (array_keys($edges[$group[$i]]) as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = true;
                        $group[] = $next;
                    }
                }
            }

            $stock = self::pickStockUnit($group, $baseRows, $baseCounts);

            // Walk the conversions out from the stock unit: 1 next = f current.
            $groupFactors = [$stock => 1.0];
            $queue = [$stock];
            while ($queue) {
                $current = array_shift($queue);
                foreach (array_keys($edges[$current]) as $next) {
                    if (! isset($groupFactors[$next])) {
                        $groupFactors[$next] = $edges[$next][$current] * $groupFactors[$current];
                        $queue[] = $next;
                    }
                }
            }

            foreach ($groupFactors as $unit => $factor) {
                $stockUnitOf[$unit] = $stock;
                $factors[$unit] = $factor;
            }

            // The primary group - what a blank unit and the reports mean - is the one with a
            // stock row and the most conversions, then the most units, then the first row.
            $score = [isset($baseRows[$stock]) ? 1 : 0, $baseCounts[$stock] ?? 0, count($group)];
            if ($primaryScore === null || $score > $primaryScore) {
                [$primary, $primaryScore] = [$stock, $score];
            }
        }

        return new self($primary, $stockUnitOf, $factors, $baseRows, $labels);
    }

    /** The primary stock row (what a blank unit posts to); null when the item has none. */
    public function stockRow(): ?object
    {
        return $this->primary === null ? null : ($this->baseRows[$this->primary] ?? null);
    }

    /** The primary stock unit as SAP spells it. */
    public function unit(): string
    {
        return $this->primary === null ? '' : $this->labels[$this->primary];
    }

    /** The row stock in $unit lives on; null when that unit's group has no base row. */
    public function stockRowFor(?string $unit): ?object
    {
        $stock = $this->stockUnitKey($unit);

        return $stock === null ? null : ($this->baseRows[$stock] ?? null);
    }

    /** The stock unit $unit converts into, as SAP spells it. */
    public function unitFor(?string $unit): string
    {
        $stock = $this->stockUnitKey($unit);

        return $stock === null ? '' : $this->labels[$stock];
    }

    /**
     * How many stock units (of stockRowFor($unit)) one $unit holds; null when the item
     * has no such unit. A blank unit is taken to be the primary stock unit.
     */
    public function factor(?string $unit): ?float
    {
        $key = self::key($unit);

        return $key === '' ? ($this->primary === null ? null : 1.0) : ($this->factors[$key] ?? null);
    }

    /** @return array<string, float> primary stock units per one UPPER unit of its group */
    public function factors(): array
    {
        return array_filter($this->factors, fn ($unit) => $this->stockUnitOf[$unit] === $this->primary, ARRAY_FILTER_USE_KEY);
    }

    private function stockUnitKey(?string $unit): ?string
    {
        $key = self::key($unit);

        return $key === '' ? $this->primary : ($this->stockUnitOf[$key] ?? null);
    }

    /**
     * In one group of linked units: the base unit most conversion rows point at, else
     * the group's first base row, else - a group with no base row - its most used base.
     */
    private static function pickStockUnit(array $group, array $baseRows, array $baseCounts): string
    {
        $withRow = array_values(array_filter($group, fn ($unit) => isset($baseRows[$unit])));
        $candidates = $withRow ?: $group;

        usort($candidates, function ($a, $b) use ($baseCounts, $baseRows) {
            return [($baseCounts[$b] ?? 0), ($baseRows[$a]->id ?? PHP_INT_MAX)]
                <=> [($baseCounts[$a] ?? 0), ($baseRows[$b]->id ?? PHP_INT_MAX)];
        });

        return $candidates[0];
    }

    private static function key(?string $unit): string
    {
        return strtoupper(trim((string) $unit));
    }
}
