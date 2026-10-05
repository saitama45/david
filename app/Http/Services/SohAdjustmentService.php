<?php

namespace App\Http\Services;

use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\User;
use App\Services\MonthEndStockAdjustment;
use App\Support\ItemStockUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock on hand corrections that wait for an approver.
 *
 * An adjustment is filed as a stock history row that no balance counts yet: action
 * `soh_adjustment`, its quantity the signed difference in the item's stock unit. Approving
 * turns that same row into the `add` or `out` movement every balance reads; rejecting
 * parks it under an action nothing counts. Nothing is ever deleted.
 */
class SohAdjustmentService
{
    public const PENDING = 'soh_adjustment';

    public const REJECTED = 'soh_adjustment_rejected';

    /** The balance the stock history gives, as MonthEndStockAdjustment::balance() reads it. */
    private const BALANCE = "COALESCE(SUM(CASE WHEN action IN ('add','add_quantity') THEN quantity WHEN action IN ('out','deduct','log_usage') THEN -quantity ELSE 0 END), 0)";

    public function __construct(private MonthEndStockAdjustment $ledger) {}

    /**
     * Stock on hand at a store per stock row.
     *
     * @param  array<int, int>  $stockRowIds
     * @return Collection<int, float> stock row id => quantity in that row's unit
     */
    public function balances(int $branchId, array $stockRowIds): Collection
    {
        if (empty($stockRowIds)) {
            return collect();
        }

        return DB::table('product_inventory_stock_managers')
            ->where('store_branch_id', $branchId)
            ->whereIn('product_inventory_id', $stockRowIds)
            ->groupBy('product_inventory_id')
            ->selectRaw('product_inventory_id, ' . self::BALANCE . ' as balance')
            ->pluck('balance', 'product_inventory_id')
            ->map(fn ($balance) => (float) $balance);
    }

    /**
     * The adjustments of a store that wait for approval, newest first.
     *
     * @param  array<int, int>|null  $stockRowIds  only these stock rows; null for all
     */
    public function pending(int $branchId, ?array $stockRowIds = null, ?string $search = null): Collection
    {
        return ProductInventoryStockManager::with('sapMasterfile')
            ->where('store_branch_id', $branchId)
            ->where('action', self::PENDING)
            ->where('is_stock_adjustment_approved', false)
            ->when($stockRowIds !== null, fn ($query) => $query->whereIn('product_inventory_id', $stockRowIds))
            ->when($search, fn ($query) => $query->whereHas('sapMasterfile', function ($item) use ($search) {
                $item->where('ItemDescription', 'like', "%{$search}%")
                    ->orWhere('ItemCode', 'like', "%{$search}%");
            }))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * File a correction to a new stock on hand, counted in the unit of the row picked
     * (500 Gm of an item kept by the 1,000 Gm Bag is an adjustment to 0.5 Bag).
     *
     * @throws ValidationException
     */
    public function requestNewQuantity(SAPMasterfile $unitRow, int $branchId, float $newQuantity, string $remarks, User $user): ProductInventoryStockManager
    {
        [$stockRow, $factor] = $this->stockRowAndFactor($unitRow);
        $current = ($this->balances($branchId, [$stockRow->id])->get($stockRow->id) ?? 0.0) / $factor;
        $difference = round($newQuantity - $current, 6);

        if ($difference == 0.0) {
            throw ValidationException::withMessages(['new_quantity' => 'The new SOH is the same as the current SOH.']);
        }

        $unit = trim((string) $unitRow->AltUOM);

        return $this->file($stockRow, $branchId, $difference * $factor, sprintf(
            '%s | new SOH %s %s, was %s %s',
            $remarks, $this->number($newQuantity), $unit, $this->number($current), $unit
        ), $user);
    }

    /**
     * File a correction by its difference (+ adds, - deducts) in the unit of the row
     * picked, as the SOH Update upload gives it.
     *
     * @throws ValidationException
     */
    public function requestDifference(SAPMasterfile $unitRow, int $branchId, float $difference, ?string $remarks, User $user): ProductInventoryStockManager
    {
        [$stockRow, $factor] = $this->stockRowAndFactor($unitRow);

        if (round($difference, 6) == 0.0) {
            throw ValidationException::withMessages(['new_quantity' => 'The variance is 0.']);
        }

        return $this->file($stockRow, $branchId, $difference * $factor, sprintf(
            '%s | variance %s%s %s',
            trim((string) $remarks) !== '' ? trim($remarks) : 'SOH Update upload',
            $difference > 0 ? '+' : '', $this->number($difference), trim((string) $unitRow->AltUOM)
        ), $user);
    }

    /**
     * Approve: the waiting row becomes the stock movement itself, dated today, and the
     * store's cached balance is brought in line.
     */
    public function approve(ProductInventoryStockManager $adjustment, User $user): void
    {
        DB::transaction(function () use ($adjustment, $user) {
            $row = $this->lockPending($adjustment);
            $difference = (float) $row->quantity;

            $row->update([
                'action' => $difference > 0 ? 'add' : 'out',
                'quantity' => abs($difference),
                'is_stock_adjustment' => true,
                'is_stock_adjustment_approved' => true,
                'transaction_date' => now('Asia/Manila')->toDateString(),
                'remarks' => $row->remarks . ' | approved by ' . $user->name,
            ]);

            $this->ledger->refreshCache((int) $row->product_inventory_id, (int) $row->store_branch_id);
        });
    }

    /** Reject: the row stays in the stock history for the record, under an action no balance counts. */
    public function reject(ProductInventoryStockManager $adjustment, User $user): void
    {
        DB::transaction(function () use ($adjustment, $user) {
            $row = $this->lockPending($adjustment);

            $row->update([
                'action' => self::REJECTED,
                'remarks' => $row->remarks . ' | rejected by ' . $user->name,
            ]);
        });
    }

    /**
     * The row an item's stock lives on, and how many of its unit one of the picked row's holds.
     *
     * @return array{0: object, 1: float}
     */
    private function stockRowAndFactor(SAPMasterfile $unitRow): array
    {
        $stockUnit = ItemStockUnit::forItem($unitRow->ItemCode, $unitRow->entity_id !== null ? (int) $unitRow->entity_id : null);
        $stockRow = $stockUnit->stockRowFor($unitRow->AltUOM);
        $factor = $stockUnit->factor($unitRow->AltUOM);

        if (! $stockRow || ! $factor) {
            throw ValidationException::withMessages([
                'new_quantity' => "{$unitRow->AltUOM} has no conversion in the SAP Masterlist to the unit the stock of {$unitRow->ItemCode} is kept in.",
            ]);
        }

        return [$stockRow, (float) $factor];
    }

    /** One adjustment may wait per item and store: two would both be measured from the same SOH. */
    private function file(object $stockRow, int $branchId, float $difference, string $remarks, User $user): ProductInventoryStockManager
    {
        if ($this->pending($branchId, [$stockRow->id])->isNotEmpty()) {
            throw ValidationException::withMessages([
                'new_quantity' => 'This item already has an adjustment waiting for approval at this store.',
            ]);
        }

        return ProductInventoryStockManager::create([
            'product_inventory_id' => $stockRow->id,
            'store_branch_id' => $branchId,
            'quantity' => round($difference, 10),
            'action' => self::PENDING,
            'unit_cost' => 0,
            'total_cost' => 0,
            'transaction_date' => now('Asia/Manila')->toDateString(),
            'is_stock_adjustment' => true,
            'is_stock_adjustment_approved' => false,
            'remarks' => 'SOH Adjustment: ' . $remarks . ' | requested by ' . $user->name,
        ]);
    }

    private function lockPending(ProductInventoryStockManager $adjustment): ProductInventoryStockManager
    {
        $row = ProductInventoryStockManager::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();

        if ($row->action !== self::PENDING || $row->is_stock_adjustment_approved) {
            throw ValidationException::withMessages(['selectedItems' => 'This adjustment is no longer waiting for approval.']);
        }

        return $row;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
