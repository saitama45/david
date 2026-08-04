<?php

use App\Enum\OrderStatus;
use App\Models\StoreOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile mass regular orders whose items carry commit flags while the order
 * itself is still `approved`.
 *
 * A handful of orders had `store_order_items.committed_by` written by a direct
 * bulk SQL update (all rows share one identical `committed_date`, no audit rows,
 * `commiter_id` left NULL), which bypassed `updateOrderStatusBasedOnCommits()`.
 * The result is a grid that shows an APPROVED badge over items the system
 * considers committed — and the CS Mass Commits "Change UoM" function correctly
 * refuses to touch them, with no visible reason why.
 *
 * This replays the canonical status logic so the order status matches the item
 * flags, and backfills the order-level commit metadata that `confirmAll()` would
 * normally have written. Idempotent: re-running it finds nothing to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orders = StoreOrder::withoutEntityScope()
            ->where('variant', 'mass regular')
            ->where('order_status', OrderStatus::APPROVED->value)
            ->whereHas('store_order_items', fn ($q) => $q->whereNotNull('committed_by'))
            ->get();

        foreach ($orders as $order) {
            // Canonical logic: fully committed -> committed, some -> partial_committed.
            $order->updateOrderStatusBasedOnCommits();

            if ($order->order_status === OrderStatus::APPROVED->value) {
                // Nothing was actually committed after all; leave it alone.
                continue;
            }

            $committedItem = $order->store_order_items()
                ->whereNotNull('committed_by')
                ->orderByDesc('committed_date')
                ->first();

            $order->forceFill([
                'commiter_id' => $order->commiter_id ?: $committedItem?->committed_by,
                'commited_action_date' => $order->commited_action_date ?: $committedItem?->committed_date,
            ])->save();

            Log::info('Reconciled mass regular order status with its item commit flags', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'new_status' => $order->order_status,
                'commiter_id' => $order->commiter_id,
            ]);
        }
    }

    /**
     * Not reversible: the prior state was inconsistent data, not a schema change,
     * and the original (wrong) `approved` status carries no information worth
     * restoring.
     */
    public function down(): void
    {
        // no-op
    }
};
