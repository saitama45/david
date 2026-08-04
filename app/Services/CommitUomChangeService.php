<?php

namespace App\Services;

use App\Models\SAPMasterfile;
use App\Models\StoreOrderItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Handles the "Change UoM" function of the CS Mass Commits (COMMIT) phase.
 *
 * A UoM change re-expresses an already ordered/approved item in one of the
 * alternative UoMs published for it in the SAP masterfile. Quantities are
 * converted with:
 *
 *     Qty of updated UoM = ROUND UP((Qty of original UoM * BaseQty of original UoM) / BaseQty of updated UoM)
 *
 * Because every downstream screen (Approved > Committed > Received) reads
 * `store_order_items.uom`, overwriting it here cascades the new UoM through the
 * whole item journey.
 */
class CommitUomChangeService
{
    /**
     * Order statuses where the commit phase is over and the UoM may no longer change.
     */
    private const LOCKED_ORDER_STATUSES = ['committed', 'received', 'incomplete'];

    /**
     * Build the Alt UoM dropdown options for the given item codes, keyed by item code.
     *
     * @param  iterable<string>  $itemCodes
     * @return array<string, array<int, array{label: string, value: string, base_qty: float}>>
     */
    public function optionsForItemCodes($itemCodes): array
    {
        $itemCodes = collect($itemCodes)->filter()->unique()->values();

        if ($itemCodes->isEmpty()) {
            return [];
        }

        $options = [];

        // Chunk the IN() list: SQL Server caps parameters at 2100 per statement.
        foreach ($itemCodes->chunk(1000) as $chunk) {
            SAPMasterfile::query()
                ->select(['ItemCode', 'AltUOM', 'BaseQty'])
                ->whereIn('ItemCode', $chunk->all())
                ->where('is_active', 1)
                ->whereNotNull('AltUOM')
                ->orderBy('ItemCode')
                ->orderBy('AltUOM')
                ->get()
                ->each(function ($row) use (&$options) {
                    $baseQty = (float) $row->BaseQty;

                    // A zero/negative BaseQty would make the conversion undefined.
                    if ($baseQty <= 0) {
                        return;
                    }

                    $options[$row->ItemCode][$row->AltUOM] = [
                        'label' => $row->AltUOM,
                        'value' => $row->AltUOM,
                        'base_qty' => $baseQty,
                    ];
                });
        }

        return collect($options)
            ->map(fn ($uoms) => array_values($uoms))
            ->all();
    }

    /**
     * Qty of updated UoM = ROUND UP((qty * oldBaseQty) / newBaseQty)
     */
    public function convertQuantity($quantity, float $oldBaseQty, float $newBaseQty): float
    {
        $quantity = (float) $quantity;

        if ($quantity == 0.0 || $newBaseQty <= 0) {
            return $quantity;
        }

        $converted = ($quantity * $oldBaseQty) / $newBaseQty;

        // Round off binary floating point artifacts first, otherwise a value that
        // is mathematically 9 but stored as 9.000000000000002 would ceil to 10.
        return (float) ceil(round($converted, 6));
    }

    /**
     * Change the UoM of every store order item behind one CS Mass Commits grid row.
     *
     * @return array{updated: int, from_uom: string, to_uom: string, log_entry: string}
     *
     * @throws RuntimeException when the change is not allowed or cannot be resolved.
     */
    public function changeUom(
        User $user,
        string $orderDate,
        string $supplierCode,
        string $itemCode,
        string $currentUom,
        string $newUom
    ): array {
        if ($currentUom === $newUom) {
            throw new RuntimeException('The selected UoM is already the current UoM for this item.');
        }

        $newSapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)
            ->where('AltUOM', $newUom)
            ->where('is_active', 1)
            ->first();

        if (!$newSapMasterfile) {
            throw new RuntimeException("'{$newUom}' is not an available Alt UoM for item {$itemCode} in the SAP masterfile.");
        }

        $newBaseQty = (float) $newSapMasterfile->BaseQty;

        if ($newBaseQty <= 0) {
            throw new RuntimeException("The SAP masterfile has no usable BaseQty for {$itemCode} ({$newUom}).");
        }

        $oldSapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)
            ->where('AltUOM', $currentUom)
            ->first();

        $oldBaseQty = (float) ($oldSapMasterfile->BaseQty ?? 0);

        if ($oldBaseQty <= 0) {
            throw new RuntimeException("The SAP masterfile has no usable BaseQty for the current UoM {$itemCode} ({$currentUom}).");
        }

        $items = $this->itemsForRow($user, $orderDate, $supplierCode, $itemCode, $currentUom);

        if ($items->isEmpty()) {
            throw new RuntimeException('No order items found for the specified item, date and supplier.');
        }

        $this->assertNotYetCommitted($items);

        $logEntry = $this->formatLogEntry($user, $currentUom, $newUom);

        // Cost per quantity is re-expressed with the same ratio as the quantity so the
        // monetary value of the order is unchanged by the unit conversion. total_cost is
        // deliberately left alone for the same reason.
        $costRatio = $newBaseQty / $oldBaseQty;

        DB::transaction(function () use ($items, $oldBaseQty, $newBaseQty, $newUom, $newSapMasterfile, $costRatio, $logEntry) {
            foreach ($items as $item) {
                $convertedCommitted = $this->convertQuantity($item->quantity_commited, $oldBaseQty, $newBaseQty);

                $item->quantity_ordered = $this->convertQuantity($item->quantity_ordered, $oldBaseQty, $newBaseQty);
                $item->quantity_approved = $this->convertQuantity($item->quantity_approved, $oldBaseQty, $newBaseQty);
                $item->quantity_commited = $convertedCommitted;
                $item->quantity_received = $this->convertQuantity($item->quantity_received, $oldBaseQty, $newBaseQty);

                // Remember the UoM the order was placed in, once. The supplier catalog is
                // keyed by it, so this is what keeps the row's category, classification and
                // sort order intact after the switch.
                if ($item->original_uom === null) {
                    $item->original_uom = $item->uom;
                }

                $item->uom = $newUom;
                $item->sap_masterfile_id = $newSapMasterfile->id;

                if ($item->cost_per_quantity !== null) {
                    $item->cost_per_quantity = (float) $item->cost_per_quantity * $costRatio;
                }

                $item->uom_change_history = $item->uom_change_history
                    ? $item->uom_change_history . PHP_EOL . $logEntry
                    : $logEntry;

                $item->save();

                // Keep any not-yet-received placeholder in step with the new UoM.
                $item->ordered_item_receive_dates()
                    ->where('status', 'pending')
                    ->update(['quantity_received' => $convertedCommitted]);
            }
        });

        return [
            'updated' => $items->count(),
            'from_uom' => $currentUom,
            'to_uom' => $newUom,
            'log_entry' => $logEntry,
        ];
    }

    /**
     * The store order items aggregated behind a single CS Mass Commits grid row,
     * restricted to the branches the acting user is assigned to.
     *
     * @return Collection<int, StoreOrderItem>
     */
    private function itemsForRow(
        User $user,
        string $orderDate,
        string $supplierCode,
        string $itemCode,
        string $currentUom
    ): Collection {
        $userBranchIds = $user->store_branches->pluck('id');

        return StoreOrderItem::query()
            ->where('item_code', $itemCode)
            ->where('uom', $currentUom)
            ->whereHas('store_order', function ($query) use ($orderDate, $supplierCode, $userBranchIds) {
                $query->whereDate('order_date', $orderDate)
                    ->whereIn('store_branch_id', $userBranchIds)
                    ->whereHas('supplier', function ($supplierQuery) use ($supplierCode) {
                        $supplierQuery->where('supplier_code', $supplierCode);
                    });
            })
            ->with('store_order')
            ->get();
    }

    /**
     * The UoM may only change while the item is still in the commit phase.
     *
     * @param  Collection<int, StoreOrderItem>  $items
     */
    private function assertNotYetCommitted(Collection $items): void
    {
        foreach ($items as $item) {
            if ($item->committed_by !== null) {
                throw new RuntimeException('Cannot change UoM. This item has already been committed.');
            }

            $status = strtolower((string) $item->store_order?->order_status);

            if (in_array($status, self::LOCKED_ORDER_STATUSES, true)) {
                throw new RuntimeException("Cannot change UoM. The order for one of the branches is already {$status}.");
            }
        }
    }

    private function formatLogEntry(User $user, string $fromUom, string $toUom): string
    {
        $userName = trim((string) $user->full_name) ?: ('User #' . $user->id);

        return "[{$userName}] changed UoM from [{$fromUom}] to [{$toUom}]";
    }
}
