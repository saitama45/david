<?php

use App\Models\StoreOrderItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Recovers `original_uom` for items whose UoM was changed before that column
 * existed. The audit trail written by CommitUomChangeService has a fixed shape —
 * "[User] changed UoM from [X] to [Y]" — so the first entry's "from" value is the
 * UoM the order was originally placed in.
 *
 * Without this, those rows keep missing the supplier catalog (which is keyed by
 * ItemCode + SupplierCode + uom) and show a blank classification, an N/A category
 * and sort to the bottom of the CS Mass Commits grid.
 */
return new class extends Migration
{
    public function up(): void
    {
        $items = StoreOrderItem::withoutEntityScope()
            ->whereNull('original_uom')
            ->whereNotNull('uom_change_history')
            ->get();

        foreach ($items as $item) {
            if (!preg_match('/changed UoM from \[(.*?)\] to \[/', (string) $item->uom_change_history, $m)) {
                Log::warning('Could not parse original UoM from uom_change_history', [
                    'store_order_item_id' => $item->id,
                    'uom_change_history' => $item->uom_change_history,
                ]);

                continue;
            }

            $originalUom = trim($m[1]);

            if ($originalUom === '' || $originalUom === $item->uom) {
                continue;
            }

            $item->original_uom = $originalUom;
            $item->save();

            Log::info('Backfilled original_uom from the UoM change audit trail', [
                'store_order_item_id' => $item->id,
                'item_code' => $item->item_code,
                'original_uom' => $originalUom,
                'current_uom' => $item->uom,
            ]);
        }
    }

    /**
     * Not reversible: `original_uom` is derived data and the column drop in the
     * preceding migration already removes it.
     */
    public function down(): void
    {
        // no-op
    }
};
