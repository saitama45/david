<?php

namespace App\Support;

use App\Models\POSMasterfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A Sub-Prep (a mix the store prepares itself, POS Category SUB-PREP) is made of the raw
 * materials on its BOM and holds no stock of its own. Wherever a quantity is filed under its
 * code - a drink whose BOM uses the mix, a month end count of the mix, wastage against its SAP
 * row - it stands for those raw materials: the BOM Qty of each per one unit of the Sub-Prep
 * (its POS UOM), as a sale reads a BOM. 30 Gm of a mix whose BOM makes 1 Batch of 430 Gm from
 * 25 Gm of matcha used 30 / 430 x 25 Gm of matcha.
 *
 * TC also lists its mixes on the SAP Masterlist (SP-0001 Matcha Prep Mix, 430 Gm = 1 Batch) so
 * they can be counted. Those SAP rows only convert the unit a quantity was filed in; they never
 * hold stock. A Sub-Prep with no BOM is not broken down and stays an item of its own.
 */
final class SubPrepBreakdown
{
    /** A Sub-Prep made of another is followed this deep, which also ends a BOM that loops. */
    private const MAX_DEPTH = 5;

    /**
     * @param  array<string, array<string, mixed>>  $subPreps  "entity|CODE" => code, description,
     *                                                         pos_unit (UPPER), ingredients (item_code, item_description, unit, bom_qty, unit_cost),
     *                                                         stock (ItemStockUnit of its SAP rows), unit_keys (UPPER units its SAP rows name)
     */
    private function __construct(private readonly array $subPreps) {}

    /** The Sub-Preps (with a BOM) of the given entities. */
    public static function forEntities(array $entityIds): self
    {
        $entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds))));

        if ($entityIds === []) {
            return new self([]);
        }

        $items = DB::table('pos_masterfiles')
            ->whereIn('entity_id', $entityIds)
            ->whereRaw('UPPER(LTRIM(RTRIM([Category]))) = ?', [POSMasterfile::SUB_PREP_CATEGORY])
            ->orderBy('id')
            ->get(['entity_id', 'POSCode', 'POSDescription', 'UOM'])
            ->filter(fn ($item) => filled(trim((string) $item->POSCode)))
            ->unique(fn ($item) => self::index($item->entity_id, $item->POSCode));

        if ($items->isEmpty()) {
            return new self([]);
        }

        $codes = $items->pluck('POSCode')->map(fn ($code) => trim((string) $code))->unique()->values();
        $lines = collect();
        $sapRows = collect();

        // SQL Server allows 2100 parameters per query.
        foreach ($codes->chunk(1000) as $chunk) {
            $lines = $lines->merge(DB::table('pos_masterfiles_bom')
                ->whereIn('entity_id', $entityIds)
                ->whereIn('POSCode', $chunk->values()->all())
                ->orderBy('id')
                ->get(['entity_id', 'POSCode', 'ItemCode', 'ItemDescription', 'BOMUOM', 'BOMQty', 'UnitCost']));

            $sapRows = $sapRows->merge(DB::table('sap_masterfiles')
                ->whereIn('entity_id', $entityIds)
                ->whereIn('ItemCode', $chunk->values()->all())
                ->orderBy('id')
                ->get(['id', 'entity_id', 'ItemCode', 'AltUOM', 'BaseUOM', 'AltQty', 'BaseQty']));
        }

        $linesBySubPrep = $lines
            ->filter(fn ($line) => filled(trim((string) $line->ItemCode)) && is_numeric($line->BOMQty) && (float) $line->BOMQty != 0)
            ->groupBy(fn ($line) => self::index($line->entity_id, $line->POSCode));
        $rowsBySubPrep = $sapRows->groupBy(fn ($row) => self::index($row->entity_id, $row->ItemCode));

        $subPreps = [];

        foreach ($items as $item) {
            $index = self::index($item->entity_id, $item->POSCode);
            $bom = $linesBySubPrep->get($index);

            // Without a BOM there is nothing to break it down into.
            if ($bom === null || $bom->isEmpty()) {
                continue;
            }

            $rows = $rowsBySubPrep->get($index, collect());

            $subPreps[$index] = [
                'code' => trim((string) $item->POSCode),
                'description' => trim((string) $item->POSDescription),
                'pos_unit' => self::key($item->UOM),
                // A negative BOM Qty is kept: it adds that item back, as a sale's would.
                'ingredients' => $bom
                    ->groupBy(fn ($line) => self::key($line->ItemCode).'|'.self::key($line->BOMUOM))
                    ->map(function (Collection $same) {
                        $first = $same->first();
                        $qty = (float) $same->sum(fn ($line) => (float) $line->BOMQty);

                        return [
                            'item_code' => trim((string) $first->ItemCode),
                            'item_description' => $first->ItemDescription,
                            'unit' => trim((string) $first->BOMUOM),
                            'bom_qty' => $qty,
                            // The BOM unit cost of one BOM unit, repeated lines weighted by their quantity.
                            'unit_cost' => $qty != 0.0
                                ? (float) $same->sum(fn ($line) => (float) $line->BOMQty * (float) $line->UnitCost) / $qty
                                : (float) $first->UnitCost,
                        ];
                    })
                    ->values()
                    ->all(),
                'stock' => ItemStockUnit::fromRows($rows),
                'unit_keys' => $rows->flatMap(fn ($row) => [self::key($row->AltUOM), self::key($row->BaseUOM)])
                    ->filter()->unique()->values()->all(),
            ];
        }

        return new self($subPreps);
    }

    public function isEmpty(): bool
    {
        return $this->subPreps === [];
    }

    /** Whether $code is a Sub-Prep with a BOM in the entity. */
    public function isSubPrep(?int $entityId, ?string $code): bool
    {
        return $entityId !== null && isset($this->subPreps[self::index($entityId, $code)]);
    }

    /** @return array<int, string> every Sub-Prep code, of any entity loaded */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->subPreps, 'code')));
    }

    /** @return array<int, string> the Sub-Prep codes of one entity */
    public function codesOf(?int $entityId): array
    {
        $prefix = (int) $entityId.'|';

        return array_values(array_map(
            fn (array $subPrep) => $subPrep['code'],
            array_filter($this->subPreps, fn ($index) => str_starts_with($index, $prefix), ARRAY_FILTER_USE_KEY)
        ));
    }

    /** The Sub-Prep's name. */
    public function description(?int $entityId, string $code): string
    {
        return $this->subPreps[self::index($entityId, $code)]['description'] ?? '';
    }

    /** The unit its BOM is for, as its POS item gives it (UPPER; blank when it has none). */
    public function posUnit(?int $entityId, string $code): string
    {
        return $this->subPreps[self::index($entityId, $code)]['pos_unit'] ?? '';
    }

    /**
     * The raw materials the Sub-Preps are made of, through any Sub-Prep inside them.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function ingredientCodes(?int $entityId, array $codes): array
    {
        $found = [];

        foreach ($codes as $code) {
            foreach ($this->breakDown($entityId, $code, null, 1.0) ?? [] as $line) {
                $found[self::key($line['item_code'])] = $line['item_code'];
            }
        }

        return array_values($found);
    }

    /**
     * What $qty of the Sub-Prep, filed in $unit, is made of: one line per raw material and BOM
     * unit (item_code, unit, qty, unit_cost per BOM unit, sub_prep_code, sub_prep_description -
     * the Sub-Prep the quantity was filed under). A blank unit is the unit its BOM is for.
     * Null when $unit does not convert into that unit, or $code is not a Sub-Prep.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function breakDown(?int $entityId, string $code, ?string $unit, float $qty): ?array
    {
        $lines = $this->lines($entityId, $code, $unit, $qty, 0);

        if ($lines === null) {
            return null;
        }

        $outer = $this->subPreps[self::index($entityId, $code)];

        return array_map(fn (array $line) => $line + [
            'sub_prep_code' => $outer['code'],
            'sub_prep_description' => $outer['description'],
        ], $lines);
    }

    /**
     * How many units of what its BOM is for one $unit of the Sub-Prep holds: 1 for that unit or
     * a blank one, else through the conversions of its SAP rows (430 Gm = 1 Batch). Null when
     * they do not link the two.
     */
    public function posUnitsPer(?int $entityId, string $code, ?string $unit): ?float
    {
        $subPrep = $this->subPreps[self::index($entityId, $code)] ?? null;

        if ($subPrep === null) {
            return null;
        }

        /** @var ItemStockUnit $stock */
        $stock = $subPrep['stock'];
        // A Sub-Prep with no POS UOM is taken to be in the unit its SAP stock row is in.
        $posUnit = $subPrep['pos_unit'] !== '' ? $subPrep['pos_unit'] : self::key($stock->unit());
        $unitKey = self::key($unit);

        if ($unitKey === '' || $unitKey === $posUnit) {
            return 1.0;
        }

        if ($posUnit === '') {
            return null;
        }

        $perUnit = $stock->factor($unitKey);
        $perPosUnit = $stock->factor($posUnit);

        // Two units SAP does not link have no conversion between them.
        if ($perUnit === null || ! $perPosUnit || self::key($stock->unitFor($unitKey)) !== self::key($stock->unitFor($posUnit))) {
            return null;
        }

        return $perUnit / $perPosUnit;
    }

    /**
     * posUnitsPer() for every unit its SAP rows name, and its POS unit.
     *
     * @return array<string, float> UPPER unit => units of what its BOM is for
     */
    public function unitFactors(?int $entityId, string $code): array
    {
        $subPrep = $this->subPreps[self::index($entityId, $code)] ?? null;

        if ($subPrep === null) {
            return [];
        }

        $factors = [];

        foreach (array_unique(array_merge($subPrep['unit_keys'], [$subPrep['pos_unit']])) as $unit) {
            if ($unit !== '' && ($factor = $this->posUnitsPer($entityId, $code, $unit)) !== null) {
                $factors[$unit] = $factor;
            }
        }

        return $factors;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function lines(?int $entityId, string $code, ?string $unit, float $qty, int $depth): ?array
    {
        $subPrep = $this->subPreps[self::index($entityId, $code)] ?? null;
        $perUnit = $subPrep === null ? null : $this->posUnitsPer($entityId, $code, $unit);

        if ($perUnit === null) {
            return null;
        }

        $lines = [];

        foreach ($subPrep['ingredients'] as $ingredient) {
            $used = $qty * $perUnit * $ingredient['bom_qty'];

            // A mix made with another mix: that one is broken down too.
            if ($depth < self::MAX_DEPTH && $this->isSubPrep($entityId, $ingredient['item_code'])) {
                $inner = $this->lines($entityId, $ingredient['item_code'], $ingredient['unit'], $used, $depth + 1);

                if ($inner !== null) {
                    array_push($lines, ...$inner);

                    continue;
                }
            }

            $lines[] = [
                'item_code' => $ingredient['item_code'],
                'item_description' => $ingredient['item_description'],
                'unit' => $ingredient['unit'],
                'qty' => $used,
                'unit_cost' => $ingredient['unit_cost'],
            ];
        }

        return $lines;
    }

    private static function index($entityId, ?string $code): string
    {
        return (int) $entityId.'|'.self::key($code);
    }

    private static function key(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
