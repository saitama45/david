<?php

namespace App\Http\Services;

use App\Enums\IntercoStatus;
use App\Models\OrderedItemReceiveDate;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Support\ItemStockUnit;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receiving an interco transfer.
 *
 * Nothing leaves the sending store until the receiving store receives it. Receiving opens as
 * soon as the transfer is approved: the sending store's commit (Store Commits) is optional and
 * only lets it restate a quantity until the receiving store starts receiving.
 *
 * Final Receive All posts every line whose received quantity equals the quantity sent - out of
 * the sending store and into the receiving one, at once. A line that differs, short or over,
 * is not posted: it waits for the sending store (FOR_CONFIRMATION), which either confirms the
 * received quantity (posted as received) or re-sends the line to the receiving store to
 * recount. The transfer is received, and locked, once every line is posted.
 */
class IntercoReceivingService
{
    /** A receipt that differs from what was sent, waiting for the sending store's answer. */
    public const FOR_CONFIRMATION = 'for_confirmation';

    /** Receipts the receiving store still holds: not posted and not with the sending store. */
    public const OPEN = ['pending', 'received'];

    /** Transfer statuses that can be received. An approved transfer needs no commit first. */
    public const RECEIVABLE = [IntercoStatus::APPROVED, IntercoStatus::COMMITTED, IntercoStatus::IN_TRANSIT];

    /**
     * Why the transfer's items cannot be received or changed, or null while they can.
     */
    public function receivingProblem(StoreOrder $order): ?string
    {
        if ($problem = $this->lockedProblem($order)) {
            return $problem;
        }

        if (in_array($order->interco_status, self::RECEIVABLE, true)) {
            return null;
        }

        return match ($order->interco_status) {
            IntercoStatus::DISAPPROVED => 'This transfer was disapproved and cannot be received.',
            IntercoStatus::RECEIVED => 'This transfer is already received.',
            default => 'This transfer can be received once it is approved.',
        };
    }

    /**
     * Why the transfer is closed for good, or null while it is open: every line is posted.
     */
    public function lockedProblem(StoreOrder $order): ?string
    {
        if (! $order->receiving_finalized_at) {
            return null;
        }

        $by = $order->receivingFinalizedBy?->full_name;

        // Stored as Manila wall time, like received_date, so it is shown as stored.
        return 'This transfer was fully received on '
            .$order->receiving_finalized_at->format('M j, Y g:i A')
            .($by ? " ({$by})" : '')
            .'. Its items can no longer be changed or received.';
    }

    /**
     * Give every line of a receivable transfer its receiving row, at the quantity to send. A
     * commit used to create them; without one nothing else does. Idempotent.
     *
     * @return int rows created
     */
    public function ensureLines(StoreOrder $order): int
    {
        if ($order->receiving_finalized_at || ! in_array($order->interco_status, self::RECEIVABLE, true)) {
            return 0;
        }

        $receiving = app(OrderReceivingService::class);

        return $order->store_order_items()->whereDoesntHave('ordered_item_receive_dates')->get()
            ->filter(fn (StoreOrderItem $item) => $receiving->ensureReceivingPlaceholder($item))
            ->count();
    }

    /**
     * The receiving store's first action moves an approved (or committed) transfer in transit,
     * which closes the sending store's chance to restate a quantity in Store Commits.
     */
    public function startReceiving(StoreOrder $order): void
    {
        if (in_array($order->interco_status, [IntercoStatus::APPROVED, IntercoStatus::COMMITTED], true)) {
            $order->update(['interco_status' => IntercoStatus::IN_TRANSIT]);
        }
    }

    /**
     * Whether a line's receipts add up to the quantity sent, to the four decimals reports print.
     */
    public function matchesSent(StoreOrderItem $item, Collection $receipts): bool
    {
        return abs((float) $receipts->sum('quantity_received') - (float) $item->quantity_commited) < 0.00005;
    }

    /**
     * Final Receive All: post every line received as sent, send every other line to the
     * sending store for confirmation, and close the transfer once nothing is left.
     *
     * @return array{posted: int, for_confirmation: int, finalized: bool} lines posted and sent
     *
     * @throws Exception when the transfer cannot be received
     */
    public function finalReceive(StoreOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            // Locked, so two clicks cannot post the same receipts twice.
            $order = StoreOrder::lockForUpdate()->findOrFail($order->id);

            if ($problem = $this->receivingProblem($order)) {
                throw new Exception($problem);
            }

            $this->ensureLines($order);

            $toPost = collect();
            $toSender = collect();
            $posted = 0;
            $sent = 0;

            foreach ($order->store_order_items()->with('ordered_item_receive_dates')->get() as $item) {
                $receipts = $item->ordered_item_receive_dates;
                $open = $receipts->whereIn('status', self::OPEN);

                if ($open->isEmpty()) {
                    continue;
                }

                if ($this->matchesSent($item, $receipts)) {
                    $toPost = $toPost->concat($open);
                    $posted++;
                } else {
                    $toSender = $toSender->concat($open);
                    $sent++;
                }
            }

            foreach ($toSender as $receipt) {
                $receipt->update([
                    'status' => self::FOR_CONFIRMATION,
                    'received_date' => $receipt->received_date ?? Carbon::now('Asia/Manila'),
                    // A row nobody received yet still names the sending store's committer.
                    'received_by_user_id' => $receipt->status === 'pending' || ! $receipt->received_by_user_id
                        ? Auth::id() : $receipt->received_by_user_id,
                    'confirmation_requested_at' => Carbon::now('Asia/Manila'),
                    'sender_decision' => null,
                    'sender_decided_by' => null,
                    'sender_decided_at' => null,
                    'sender_remarks' => null,
                ]);
            }

            $this->postReceipts($order, $toPost);
            $this->startReceiving($order);

            return ['posted' => $posted, 'for_confirmation' => $sent, 'finalized' => $this->finishIfComplete($order)];
        });
    }

    /**
     * The sending store accepts the received quantity of the lines waiting for it ($itemId: one
     * line, null: all of them). They are posted at that quantity.
     *
     * @return int lines confirmed
     *
     * @throws Exception when the transfer is closed
     */
    public function confirmBySender(StoreOrder $order, ?int $itemId = null): int
    {
        return DB::transaction(function () use ($order, $itemId) {
            $order = StoreOrder::lockForUpdate()->findOrFail($order->id);

            if ($problem = $this->lockedProblem($order)) {
                throw new Exception($problem);
            }

            $receipts = $this->awaitingSender($order, $itemId);

            if ($receipts->isEmpty()) {
                return 0;
            }

            $this->postReceipts($order, $receipts, [
                'sender_decision' => 'confirmed',
                'sender_decided_by' => Auth::id(),
                'sender_decided_at' => Carbon::now('Asia/Manila'),
            ]);
            $this->finishIfComplete($order);

            return $receipts->pluck('store_order_item_id')->unique()->count();
        });
    }

    /**
     * The sending store does not accept the received quantity: the lines ($itemId: one, null:
     * all waiting) go back to the receiving store to recount and Final Receive All again.
     *
     * @return int lines re-sent
     *
     * @throws Exception when the transfer is closed
     */
    public function resendBySender(StoreOrder $order, ?int $itemId = null, ?string $remarks = null): int
    {
        return DB::transaction(function () use ($order, $itemId, $remarks) {
            $order = StoreOrder::lockForUpdate()->findOrFail($order->id);

            if ($problem = $this->lockedProblem($order)) {
                throw new Exception($problem);
            }

            $receipts = $this->awaitingSender($order, $itemId);

            foreach ($receipts as $receipt) {
                $receipt->update([
                    'status' => 'received',
                    'sender_decision' => 'resent',
                    'sender_decided_by' => Auth::id(),
                    'sender_decided_at' => Carbon::now('Asia/Manila'),
                    'sender_remarks' => $remarks,
                ]);
            }

            return $receipts->pluck('store_order_item_id')->unique()->count();
        });
    }

    /**
     * The lines waiting for the sending store, as Interco Show lists them: what was sent, what
     * the receiving store received, and the difference.
     */
    public function linesAwaitingSender(StoreOrder $order): array
    {
        return $this->awaitingSender($order)
            ->load(['store_order_item.ordered_item_receive_dates', 'received_by_user'])
            ->groupBy('store_order_item_id')
            ->map(function (Collection $receipts) {
                $item = $receipts->first()->store_order_item;
                $sent = (float) $item->quantity_commited;
                $received = (float) $item->ordered_item_receive_dates->sum('quantity_received');

                return [
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'description' => $item->item_description,
                    'uom' => $item->uom,
                    'sent' => $sent,
                    'received' => $received,
                    'variance' => round($received - $sent, 4),
                    'remarks' => $receipts->pluck('remarks')->filter()->unique()->implode(', '),
                    'received_by' => $receipts->first()->received_by_user?->full_name,
                    'requested_at' => $receipts->max('confirmation_requested_at')?->format('M j, Y g:i A'),
                ];
            })
            ->values()
            ->all();
    }

    private function awaitingSender(StoreOrder $order, ?int $itemId = null)
    {
        return OrderedItemReceiveDate::whereHas('store_order_item', fn ($q) => $q->where('store_order_id', $order->id))
            ->when($itemId, fn ($q) => $q->where('store_order_item_id', $itemId))
            ->where('status', self::FOR_CONFIRMATION)
            ->get();
    }

    /**
     * Mark the transfer received and lock it once every receipt is posted.
     */
    private function finishIfComplete(StoreOrder $order): bool
    {
        $receipts = OrderedItemReceiveDate::whereHas('store_order_item', fn ($q) => $q->where('store_order_id', $order->id));

        if (! (clone $receipts)->exists() || (clone $receipts)->where('status', '<>', 'approved')->exists()) {
            return false;
        }

        $order->forceFill([
            'receiving_finalized_at' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
            'receiving_finalized_by' => Auth::id(),
            'interco_status' => IntercoStatus::RECEIVED,
        ])->save();

        return true;
    }

    /**
     * Post receipts to stock - out of the sending store, into the receiving store, each at its
     * received quantity in the item's stock unit - and mark them approved (in stock). Runs
     * inside the caller's transaction.
     */
    private function postReceipts(StoreOrder $order, Collection $receipts, array $attributes = []): void
    {
        if ($receipts->isEmpty()) {
            return;
        }

        // One line model shared by its receipts, so each one's quantity adds to the last.
        $receipts = OrderedItemReceiveDate::with('store_order_item')->whereKey($receipts->pluck('id'))->get();

        $aggregatedData = [];

        // 1. Aggregate quantities in BASE UOM
        foreach ($receipts as $history) {
            $storeOrderItem = $history->store_order_item;
            $itemCode = $storeOrderItem->item_code;
            $uom = $storeOrderItem->uom;

            if (! $itemCode || ! $uom) {
                Log::warning("IntercoReceivingService: Skipping history item ID {$history->id} due to incomplete data (ItemCode or UOM missing on StoreOrderItem).");

                continue;
            }

            // Stock lives on the item's SAP base-unit row; the received unit converts into it.
            $stockUnit = ItemStockUnit::forItem($itemCode);
            $stockUpdateTarget = $stockUnit->stockRowFor($uom);
            $conversionFactor = $stockUnit->factor($uom);

            if (! $stockUpdateTarget || ! $conversionFactor) {
                Log::warning("IntercoReceivingService: No SAP conversion from '{$uom}' to the stock unit of '{$itemCode}'. Skipping history item ID {$history->id}.");

                continue;
            }

            $targetId = $stockUpdateTarget->id;
            if (! isset($aggregatedData[$targetId])) {
                $aggregatedData[$targetId] = [
                    'total_base_qty' => 0,
                    'total_cost' => 0,
                    'unit_cost' => $storeOrderItem->cost_per_quantity / $conversionFactor, // per base UOM
                    'target_masterfile' => $stockUpdateTarget,
                    'store_order_item_ids' => [],
                ];
            }
            $aggregatedData[$targetId]['total_base_qty'] += $history->quantity_received * $conversionFactor;
            $aggregatedData[$targetId]['total_cost'] += $history->quantity_received * $storeOrderItem->cost_per_quantity;
            $aggregatedData[$targetId]['store_order_item_ids'][] = $storeOrderItem->id;
        }

        // 2. Out of the sending store, into the receiving store
        foreach ($aggregatedData as $data) {
            $quantity = $data['total_base_qty'];
            $target = $data['target_masterfile'];

            $this->postOutOfSendingStore($order, $quantity, $target, $data['unit_cost'], $data['total_cost']);

            ProductInventoryStock::create([
                'product_inventory_id' => $target->id,
                'store_branch_id' => $order->store_branch_id,
                'quantity' => $quantity,
                'recently_added' => $quantity,
                'used' => 0,
            ]);

            $batch = PurchaseItemBatch::create([
                'store_order_item_id' => $data['store_order_item_ids'][0],
                'product_inventory_id' => $target->id,
                'store_branch_id' => $order->store_branch_id,
                'purchase_date' => Carbon::today()->format('Y-m-d'),
                'quantity' => $quantity,
                'unit_cost' => $data['unit_cost'],
                'remaining_quantity' => $quantity,
            ]);

            $batch->product_inventory_stock_managers()->create([
                'product_inventory_id' => $target->id,
                'store_branch_id' => $order->store_branch_id,
                'quantity' => $quantity,
                'action' => 'add_quantity',
                'transaction_date' => Carbon::today()->format('Y-m-d'),
                'unit_cost' => $data['unit_cost'],
                'total_cost' => $data['total_cost'],
                'remarks' => 'From newly received interco items. (Interco Number: '.$order->interco_number.')',
            ]);
        }

        // 3. The receipts are in stock now
        foreach ($receipts as $receipt) {
            $receipt->update(array_merge([
                'status' => 'approved',
                'approval_action_by' => Auth::id(),
                'received_date' => $receipt->received_date ?? Carbon::now('Asia/Manila'),
            ], $attributes));
            $receipt->store_order_item->quantity_received += $receipt->quantity_received;
            $receipt->store_order_item->save();
        }
    }

    private function postOutOfSendingStore(StoreOrder $order, $quantity, $target, $unitCost, $totalCost): void
    {
        ProductInventoryStockManager::create([
            'product_inventory_id' => $target->id,
            'store_branch_id' => $order->sending_store_branch_id,
            'quantity' => $quantity,
            'action' => 'out',
            'transaction_date' => Carbon::today()->format('Y-m-d'),
            'remarks' => "Interco transfer to {$order->store_branch?->name} (Interco: {$order->interco_number})",
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
        ]);

        ProductInventoryStock::create([
            'product_inventory_id' => $target->id,
            'store_branch_id' => $order->sending_store_branch_id,
            'quantity' => -$quantity,
            'recently_added' => 0,
            'used' => $quantity,
        ]);

        $remaining = $quantity;
        $sendingBatches = PurchaseItemBatch::where('product_inventory_id', $target->id)
            ->where('store_branch_id', $order->sending_store_branch_id)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('purchase_date', 'asc')
            ->get();

        foreach ($sendingBatches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $deduct = min($remaining, $batch->remaining_quantity);
            $batch->remaining_quantity -= $deduct;
            $batch->save();
            $remaining -= $deduct;
        }
    }
}
