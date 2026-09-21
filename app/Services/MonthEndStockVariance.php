<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Qty / Cost Variance is the difference the month end count found between the
 * counted stock and the system's stock on hand.
 *
 * Theoretical inventory is that SOH - the same figure Stock Management shows,
 * read from the movement ledger (product_inventory_stock_managers) rather than
 * recomputed from orders, sales and wastage. Recomputing was wrong: receipts are
 * recorded in the order's UOM (a case, a pack), while the ledger stores base
 * units, so pack receipts were credited at a fraction of what was delivered.
 *
 * At level 2 approval MonthEndStockAdjustment posts the difference between SOH
 * and the count to the ledger, so:
 *
 *     theoretical = counted - (what the count adjusted the ledger by)
 *
 * A count that needed no adjustment posts nothing, which is why a missing
 * posting means the ledger already agreed with the count.
 *
 * Grain: branch + ItemCode, because that is what MECApproval2Controller
 * aggregates and posts. An item registered under two BaseUOMs is counted on two
 * lines but adjusted once, so per-line rows would double count its movements.
 */
class MonthEndStockVariance
{
    /** Ledger sign convention, shared with MonthEndStockAdjustment::balance(). */
    private const SIGNED_QTY = "CASE WHEN pm.action IN ('add','add_quantity') THEN pm.quantity
        WHEN pm.action IN ('out','deduct','log_usage') THEN -pm.quantity ELSE 0 END";

    /** Movement buckets, in the order they are tested. The rest is 'other'. */
    private const SOURCES = [
        'regular_received' => ['From newly received items.%', 'Added quantity from direct receiving%'],
        'cpo_received' => ['From newly received interco items.%'],
        'sales' => ['Deducted from store transaction%', 'Sale #%'],
        'wastage' => ['Wastage%'],
    ];

    /**
     * What each month end count adjusted the ledger by, per branch + item code.
     * Join this to the count rows; a branch/item without a row was not adjusted.
     *
     * @param  array<int, int>  $scheduleIds  the schedules being reported on
     */
    public function adjustments(array $scheduleIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('product_inventory_stock_managers as pm')
            ->join('sap_masterfiles as s', 'pm.product_inventory_id', '=', 's.id')
            ->where(function ($query) use ($scheduleIds) {
                foreach ($scheduleIds as $id) {
                    $query->orWhere('pm.remarks', 'like', '%'.$this->reference((int) $id).'%');
                }
                if (! $scheduleIds) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->groupBy('pm.store_branch_id', 's.ItemCode')
            ->select(
                'pm.store_branch_id',
                DB::raw('s.ItemCode as item_code'),
                DB::raw('SUM('.self::SIGNED_QTY.') as adjustment'),
                DB::raw('MIN(pm.product_inventory_id) as product_inventory_id')
            );
    }

    /** The latest active supplier cost per item, as the report has always taken it. */
    public function costs(): \Illuminate\Database\Query\Builder
    {
        return DB::table('supplier_items as si')
            ->joinSub(
                DB::table('supplier_items')->where('is_active', 1)
                    ->groupBy('ItemCode')->select('ItemCode', DB::raw('MAX(id) as id')),
                'latest',
                'latest.id',
                '=',
                'si.id'
            )
            ->select(DB::raw('si.ItemCode as item_code'), 'si.cost');
    }

    /**
     * The movements behind one row, so the drill-down explains the report row it
     * was opened from.
     *
     * Theoretical is taken from the count's own ledger adjustment, exactly as the
     * report takes it, and the beginning balance is what that leaves once the
     * period's movements are accounted for - so the parts always add up to the
     * figure on the row, whatever was backdated into the period afterwards.
     *
     * @return array<string, float|null>
     */
    public function breakdown(int $scheduleId, int $branchId, string $itemCode, $counted, ?string $approvedAt = null): array
    {
        $productIds = DB::table('sap_masterfiles')->where('ItemCode', $itemCode)
            ->whereColumn('BaseUOM', 'AltUOM')->pluck('id')->all();

        // The count posts to the base-stock row, so that is where its movements live.
        $posted = DB::table('product_inventory_stock_managers')
            ->where('store_branch_id', $branchId)
            ->where('remarks', 'like', '%'.$this->reference($scheduleId).'%')
            ->when($productIds, fn ($q) => $q->whereIn('product_inventory_id', $productIds))
            ->orderBy('id')
            ->get(['id', 'product_inventory_id', 'quantity', 'action']);

        if ($posted->isNotEmpty()) {
            $productIds = $posted->pluck('product_inventory_id')->unique()->all();
        }

        if (! $productIds) {
            return $this->emptyBreakdown();
        }

        $adjustment = $posted->sum(fn ($row) => in_array($row->action, ['add', 'add_quantity'], true)
            ? (float) $row->quantity : -(float) $row->quantity);
        $theoretical = (float) $counted - $adjustment;

        // This count's adjustment closes the period, the previous count's opens
        // it. Ordering by id, not date: a count is posted on the day it is
        // approved, which is after the movements it reconciles.
        $closesAt = $posted->min('id');
        if (! $closesAt && $approvedAt) {
            $closesAt = DB::table('product_inventory_stock_managers')
                ->whereIn('product_inventory_id', $productIds)->where('store_branch_id', $branchId)
                ->whereDate('transaction_date', '<=', substr($approvedAt, 0, 10))->max('id');
            $closesAt = $closesAt ? $closesAt + 1 : null;
        }

        $opensAt = DB::table('product_inventory_stock_managers')
            ->whereIn('product_inventory_id', $productIds)->where('store_branch_id', $branchId)
            ->where('remarks', 'like', '%MEC_REF::%')
            ->where('remarks', 'not like', '%'.$this->reference($scheduleId).'%')
            ->when($closesAt, fn ($q) => $q->where('id', '<', $closesAt))
            ->max('id');

        $movements = DB::table('product_inventory_stock_managers as pm')
            ->whereIn('pm.product_inventory_id', $productIds)->where('pm.store_branch_id', $branchId)
            ->when($closesAt, fn ($q) => $q->where('pm.id', '<', $closesAt))
            ->when($opensAt, fn ($q) => $q->where('pm.id', '>', $opensAt))
            ->selectRaw('pm.remarks, SUM('.self::SIGNED_QTY.') as v')->groupBy('pm.remarks')->get();

        $buckets = array_fill_keys(array_keys(self::SOURCES), 0.0) + ['other' => 0.0];
        foreach ($movements as $movement) {
            $buckets[$this->bucket((string) $movement->remarks)] += (float) $movement->v;
        }

        return [
            // The page prints received as +x and sales/wastage as -x.
            'beg_bal' => $theoretical - array_sum($buckets),
            'regular_received' => $buckets['regular_received'],
            'cpo_received' => $buckets['cpo_received'],
            'sales' => -$buckets['sales'],
            'wastage' => -$buckets['wastage'],
            'other' => $buckets['other'],
            'theoretical' => $theoretical,
        ];
    }

    private function bucket(string $remarks): string
    {
        foreach (self::SOURCES as $bucket => $patterns) {
            foreach ($patterns as $pattern) {
                if (fnmatch(str_replace('%', '*', $pattern), $remarks)) {
                    return $bucket;
                }
            }
        }

        return 'other';
    }

    private function reference(int $scheduleId): string
    {
        return 'MEC_REF::'.$scheduleId.',';
    }

    /** @return array<string, float|null> */
    private function emptyBreakdown(): array
    {
        return ['beg_bal' => null, 'regular_received' => null, 'cpo_received' => null,
            'sales' => null, 'wastage' => null, 'other' => null, 'theoretical' => null];
    }
}
