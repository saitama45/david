<?php

namespace App\Http\Services;

use App\Models\InventoryMovementAdjustment;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Carbon\Carbon;

/**
 * The Adjustment and Final Variance columns of the Inventory Movement Report.
 *
 * An adjustment is a signed quantity entered against an item's Variance, with the reason
 * for it: Final Variance = Variance + Adjustment. It explains the variance on the report
 * and nothing else - no stock is posted. A stock correction is an SOH adjustment
 * (SohAdjustmentService).
 *
 * There is one per store, item and month. The month is the To Date's, the rule
 * InventoryMovementService picks the Actual MEC count by, so an adjustment stays with the
 * count it explains while the dates are moved inside that month.
 */
class InventoryMovementAdjustmentService
{
    /**
     * The month an adjustment of a report with these dates belongs to.
     *
     * @return array{year: int, month: int}
     */
    public function period(array $filters): array
    {
        $date = Carbon::parse($filters['date_to']);

        return ['year' => $date->year, 'month' => $date->month];
    }

    /**
     * The report's rows with their adjustment and Final Variance added.
     *
     * @param  array<int, array<string, mixed>>  $rows  as InventoryMovementService::movementData() returns them
     * @return array<int, array<string, mixed>>
     */
    public function apply(array $rows, $branchId, array $filters): array
    {
        if (empty($rows) || empty($branchId)) {
            return $rows;
        }

        $adjustments = InventoryMovementAdjustment::with('adjuster')
            ->where('store_branch_id', $branchId)
            ->where($this->period($filters))
            ->get()
            ->keyBy(fn ($adjustment) => $this->itemKey($adjustment->item_code));

        return array_map(function (array $row) use ($adjustments) {
            $fields = $this->fields($adjustments->get($this->itemKey($row['sap_code'])));

            return $row + $fields + [
                // Variance + Adjustment. The + 0.0 turns a rounded -0.0 into 0.
                'final_variance_qty' => round($row['variance_qty'] + $fields['adjustment_qty'], 6) + 0.0,
            ];
        }, $rows);
    }

    /** Enters, or replaces, the adjustment of one item for the store and the month of the dates. */
    public function save(StoreBranch $branch, string $itemCode, array $filters, float $quantity, string $reason, ?string $uom, int $userId): InventoryMovementAdjustment
    {
        return InventoryMovementAdjustment::updateOrCreate(
            ['store_branch_id' => $branch->id, 'item_code' => $itemCode] + $this->period($filters),
            [
                'entity_id' => $branch->entity_id ?? app(EntityContext::class)->id(),
                'quantity' => round($quantity, 6),
                'uom' => $uom,
                'reason' => $reason,
                'adjusted_by' => $userId,
            ]
        );
    }

    /**
     * What the page and the exports show of an adjustment; a 0 with no reason when there is none.
     *
     * @return array{adjustment_qty: float, adjustment_reason: ?string, adjustment_by: ?string, adjustment_at: ?string}
     */
    public function fields(?InventoryMovementAdjustment $adjustment): array
    {
        return [
            'adjustment_qty' => $adjustment ? (float) $adjustment->quantity : 0.0,
            'adjustment_reason' => $adjustment?->reason,
            'adjustment_by' => $adjustment?->adjuster?->full_name,
            'adjustment_at' => $adjustment?->updated_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
        ];
    }

    private function itemKey($itemCode): string
    {
        return strtoupper(trim((string) $itemCode));
    }
}
