<?php

namespace App\Services;

use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Support\ItemStockUnit;
use App\Support\StockQuantity;
use App\Support\SubPrepBreakdown;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What the final (Level 2) approval of a month end count does with a counted Sub-Prep (prep mix).
 *
 * A mix holds no stock: what is inside it is raw material that never left stock, since making a
 * mix posts nothing. So a mix's count belongs to the raw materials counted with it. Each raw
 * material counted in the same count gets the quantity inside the mixes on top of what was found
 * loose, and the mix's own stock row is brought to 0. A raw material that was not counted keeps
 * its book balance, which still holds what went into the mix.
 *
 * The part inside a mix is a movement of its own after the raw material's count, marked
 * SUBPREP_REF. It is never folded into the count movement: the month end repair tools rebuild a
 * count movement from the item's own count lines alone and would drop it.
 */
class SubPrepCountStock
{
    public const MARKER = 'SUBPREP_REF::';

    public function __construct(private MonthEndStockAdjustment $stock) {}

    /**
     * What one store's count means for its mixes:
     *   mix_codes - the counted Sub-Prep codes (UPPER), which get no count movement of their own
     *   inside    - per raw material stock row counted here: row, item_code, quantity (stock unit), sub_preps (code => quantity)
     *   mixes     - per mix stock row: row, item_code, counted (as typed), stock_qty (what the count puts there in its stock unit)
     *
     * @param  Collection<int, MonthEndCountItem>  $lines  every line of the store in the count, with sapMasterfile
     * @return array{mix_codes: array<int, string>, inside: array<int, array>, mixes: array<int, array>}
     */
    public function plan(StoreBranch $branch, Collection $lines): array
    {
        $plan = ['mix_codes' => [], 'inside' => [], 'mixes' => []];
        $entityId = (int) $branch->entity_id;
        $subPreps = SubPrepBreakdown::forEntities([$entityId]);
        $isMix = fn ($line) => $subPreps->isSubPrep($entityId, (string) $line->sapMasterfile?->ItemCode);
        $mixLines = $lines->filter($isMix);

        if ($mixLines->isEmpty()) {
            return $plan;
        }

        // The stock rows this count sets for raw materials, from their own lines.
        $counted = [];
        foreach ($lines->reject($isMix) as $line) {
            $row = ItemStockUnit::forItem((string) $line->sapMasterfile?->ItemCode, $entityId)->stockRowFor($line->uom);
            if ($row) {
                $counted[(int) $row->id] = true;
            }
        }

        $inside = [];
        $mixes = [];

        foreach ($mixLines as $line) {
            $code = (string) $line->sapMasterfile->ItemCode;
            $plan['mix_codes'][strtoupper(trim($code))] = strtoupper(trim($code));
            $parts = is_numeric($line->total_qty) && (float) $line->total_qty >= 0
                ? $subPreps->breakDown($entityId, $code, $line->uom, (float) $line->total_qty)
                : null;

            if ($parts === null) {
                throw new \RuntimeException("Count units or quantities require reconciliation for Sub-Prep {$code}.");
            }

            $mixUnit = ItemStockUnit::forItem($code, $entityId);
            $mixRow = $mixUnit->stockRowFor($line->uom);

            if ($mixRow) {
                $mixes[(int) $mixRow->id] ??= ['row' => $mixRow, 'item_code' => $code, 'counted' => [], 'stock_qty' => 0.0];
                $mixes[(int) $mixRow->id]['counted'][] = StockQuantity::normalize($line->total_qty).' '.trim((string) $line->uom);
                $mixes[(int) $mixRow->id]['stock_qty'] += (float) $line->total_qty * (float) $mixUnit->factor($line->uom);
            }

            foreach ($parts as $part) {
                $stockUnit = ItemStockUnit::forItem($part['item_code'], $entityId);
                $row = $stockUnit->stockRowFor($part['unit']);
                $factor = $stockUnit->factor($part['unit']);

                // Only onto a raw material counted here: an uncounted one's book already holds it.
                if (! $row || ! $factor || ! isset($counted[(int) $row->id])) {
                    continue;
                }

                $quantity = $part['qty'] * $factor;
                $inside[(int) $row->id] ??= ['row' => $row, 'item_code' => $part['item_code'], 'quantity' => 0.0, 'sub_preps' => []];
                $inside[(int) $row->id]['quantity'] += $quantity;
                $inside[(int) $row->id]['sub_preps'][$code] = ($inside[(int) $row->id]['sub_preps'][$code] ?? 0.0) + $quantity;
            }
        }

        $plan['mix_codes'] = array_values($plan['mix_codes']);
        $plan['inside'] = array_values($inside);
        $plan['mixes'] = array_values($mixes);

        return $plan;
    }

    /**
     * Posts the plan, after the count's own movements and inside the approval's transaction:
     * the part inside the mixes onto each raw material, then each mix row to 0.
     */
    public function post(array $plan, MonthEndSchedule $schedule, StoreBranch $branch, string $date): void
    {
        $month = Carbon::create($schedule->year, $schedule->month, 1)->format('F Y');
        $ref = self::MARKER."{$schedule->id},{$branch->id}";

        foreach ($plan['inside'] as $part) {
            $quantity = StockQuantity::normalize($part['quantity']);

            if (StockQuantity::isZero($quantity)) {
                continue;
            }

            $from = implode(', ', array_map(
                fn ($code, $qty) => $code.' '.StockQuantity::normalize($qty).' '.trim((string) $part['row']->AltUOM),
                array_keys($part['sub_preps']), $part['sub_preps']
            ));

            ProductInventoryStockManager::create([
                'product_inventory_id' => (int) $part['row']->id, 'store_branch_id' => (int) $branch->id,
                'quantity' => StockQuantity::absolute($quantity), 'action' => StockQuantity::isPositive($quantity) ? 'add' : 'out',
                'transaction_date' => $date, 'unit_cost' => 0, 'total_cost' => 0,
                'is_stock_adjustment' => true, 'is_stock_adjustment_approved' => true,
                'remarks' => "Inside the Sub-Preps counted for {$month} ({$from}); effective {$date}||{$ref}",
            ]);
            $this->stock->refreshCache((int) $part['row']->id, (int) $branch->id);
        }

        foreach ($plan['mixes'] as $mix) {
            $this->stock->post((int) $mix['row']->id, (int) $branch->id, '0', $date,
                "Sub-Prep {$mix['item_code']} holds no stock: ".implode(' + ', $mix['counted'])." counted for {$month} is in its raw materials; effective {$date}||{$ref}");
        }
    }
}
