<?php

namespace App\Services;

use App\Http\Services\InventoryMovementService;
use App\Models\MonthEndSchedule;
use App\Models\SAPMasterfile;
use App\Support\ItemStockUnit;
use App\Support\SupplierUnitCost;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qty / Cost Variance is the difference a month end count found between the counted
 * stock and the stock the period's movements leave.
 *
 * Every quantity is the Inventory Movement Report's own (InventoryMovementService) for
 * the count's period, so the two reports tally line for line: Actual Inventory is its
 * Actual MEC, Theoretical Inventory its Theoretical SOH (Supplies Used already taken
 * out) and Qty Variance its Variance, all in the item's SAP base unit. Cost is the
 * supplier cost of that same unit.
 *
 * Theoretical used to be read back from the stock ledger (counted - what the count
 * adjusted the ledger by). That knew nothing of Supplies Used and left the count in the
 * unit it was typed in, so supplies the count fully explained still showed a shortage,
 * and the cost of one unit was multiplied by a quantity in another.
 *
 * Grain: branch + ItemCode. An item counted on two lines (two units) is one row.
 */
class MonthEndStockVariance
{
    public function __construct(private readonly InventoryMovementService $movements) {}

    /**
     * The dates a count's movements are read over: the 1st of the month counted through
     * its MEC Scheduled Date. Entering the same dates in the Inventory Movement Report
     * gives the same figures.
     *
     * The date is kept inside the month counted. A count scheduled in the following month
     * (March's on April 5) is read to the end of March, because the Inventory Movement
     * Report takes its Actual MEC from the month its To date falls in - a range ending
     * in April would compare the stock with April's count.
     *
     * @return array{0: string, 1: string} [Y-m-d from, Y-m-d to]
     */
    public function period(MonthEndSchedule $schedule): array
    {
        return self::periodFor((int) $schedule->year, (int) $schedule->month, $schedule->calculated_date);
    }

    /**
     * period() for a schedule read as plain columns.
     *
     * @return array{0: string, 1: string} [Y-m-d from, Y-m-d to]
     */
    public static function periodFor(int $year, int $month, $calculatedDate): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $from->copy()->endOfMonth()->startOfDay();
        $scheduled = Carbon::parse($calculatedDate)->startOfDay();

        return [$from->toDateString(), $scheduled->max($from)->min($monthEnd)->toDateString()];
    }

    /**
     * One row per branch + item code with a level 2 approved count on the schedule.
     *
     * @param  array<int, int>  $branchIds  the stores to report on
     * @param  string|null  $itemCode  only this item, for the drill-down of one row
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(MonthEndSchedule $schedule, array $branchIds, ?string $itemCode = null): Collection
    {
        // SQL Server allows 2100 parameters per query.
        $counted = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->chunk(1000)
            ->flatMap(fn (Collection $chunk) => DB::table('month_end_count_items as meci')
                ->join('sap_masterfiles as sm', 'meci.sap_masterfile_id', '=', 'sm.id')
                ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
                ->where('meci.month_end_schedule_id', $schedule->id)
                ->whereIn('meci.branch_id', $chunk->values()->all())
                ->whereNotNull('meci.level2_approved_at')
                ->when($itemCode !== null, fn ($query) => $query->where('sm.ItemCode', $itemCode))
                ->groupBy('meci.branch_id', 'sm.ItemCode', 'sb.name', 'sb.branch_code')
                ->select(
                    DB::raw('MIN(meci.id) as id'),
                    'meci.branch_id',
                    DB::raw('sm.ItemCode as item_code'),
                    DB::raw('MAX(sm.ItemDescription) as item_description'),
                    DB::raw("CONCAT(sb.name, ' (', sb.branch_code, ')') as store_name")
                )
                ->get());

        if ($counted->isEmpty()) {
            return collect();
        }

        $itemCodes = $counted->pluck('item_code')->unique()->values();

        // The Inventory Movement Report resolves units from the item's active SAP rows. An
        // item switched off since its count keeps its row here, on the rows it still has.
        $sapItems = SAPMasterfile::query()
            ->when(
                $itemCodes->count() <= 200,
                fn ($query) => $query->whereIn('ItemCode', $itemCodes->all()),
                // A long list is slow to bind and can pass SQL Server's 2100 parameters, so
                // the items are named by the count itself; another store's extra items are unused.
                fn ($query) => $query->whereIn('ItemCode', DB::table('month_end_count_items as meci')
                    ->join('sap_masterfiles as counted', 'counted.id', '=', 'meci.sap_masterfile_id')
                    ->where('meci.month_end_schedule_id', $schedule->id)
                    ->whereNotNull('meci.level2_approved_at')
                    ->select('counted.ItemCode'))
            )
            ->orderBy('id')
            ->get()
            ->groupBy('ItemCode')
            ->flatMap(function (Collection $rows) {
                $active = $rows->filter(fn ($row) => (bool) $row->is_active);

                return $active->isNotEmpty() ? $active : $rows;
            });

        [$from, $to] = $this->period($schedule);
        $movements = array_map(
            fn (array $rows) => array_column($rows, null, 'sap_code'),
            $this->movements->movementDataForBranches($sapItems, $counted->pluck('branch_id')->all(), ['date_from' => $from, 'date_to' => $to])
        );

        $stockUnits = $sapItems->groupBy('ItemCode')->map(fn (Collection $rows) => ItemStockUnit::fromRows($rows));
        $costs = SupplierUnitCost::forItems($itemCodes);
        $mecDate = Carbon::parse($schedule->calculated_date)->format('Y-m-d');

        return $counted->map(function ($line) use ($movements, $stockUnits, $costs, $mecDate) {
            $movement = $movements[(int) $line->branch_id][$line->item_code] ?? [];
            $uom = (string) ($movement['uom'] ?? '');
            // Priced in the unit the quantities are in; an item no supplier prices has no cost.
            $cost = $costs->find((string) $line->item_code, $stockUnits->get($line->item_code), $uom) ?? 0.0;
            $actual = (float) ($movement['actual_mec'] ?? 0);
            $theoretical = (float) ($movement['theoretical_qty'] ?? 0);
            $actualCost = round($cost * $actual, 4);
            $theoreticalCost = round($cost * $theoretical, 4);

            return [
                'id' => (int) $line->id,
                'branch_id' => (int) $line->branch_id,
                'mec_date' => $mecDate,
                'store_name' => $line->store_name,
                'item_code' => $line->item_code,
                'item_description' => $movement['item_description'] ?? $line->item_description,
                'uom' => $uom,
                'cost' => $cost,
                'actual_inventory' => $actual,
                'theoretical_inventory' => $theoretical,
                'qty_variance' => (float) ($movement['variance_qty'] ?? 0),
                'actual_cost' => $actualCost,
                'theoretical_cost' => $theoreticalCost,
                'cost_variance' => round($actualCost - $theoreticalCost, 4),
                // Counted or moved in a unit SAP does not convert to the row's unit: left out of the quantities.
                'unconverted_units' => $movement['unconverted_units'] ?? [],
                // What Theoretical Inventory is made of, as the Inventory Movement Report columns.
                'breakdown' => [
                    'beg_bal' => (float) ($movement['beg_bal_qty'] ?? 0),
                    'received' => (float) ($movement['received_qty'] ?? 0),
                    'interco_in' => (float) ($movement['interco_in_qty'] ?? 0),
                    'sales' => (float) ($movement['sales_qty'] ?? 0),
                    'wastage' => (float) ($movement['wastage_qty'] ?? 0),
                    'supplies' => (float) ($movement['supplies_qty'] ?? 0),
                    'supplies_type' => $movement['supplies_type'] ?? null,
                    'interco_out' => (float) ($movement['interco_out_qty'] ?? 0),
                ],
            ];
        })->values();
    }
}
