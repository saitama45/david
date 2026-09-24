<?php

namespace App\Console\Commands;

use App\Models\SAPMasterfile;
use App\Support\ItemStockUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves stock posted to the wrong base row onto the item's stock row (dry run unless --apply).
 *
 * Before ItemStockUnit, writers picked "the BaseUOM = AltUOM row" with ->first(), so an
 * item SAP restates in a second, linked base (Can/Can next to Case/Case, 48 Can = 1 Case)
 * got stock on either row. Each misplaced ledger entry is recomputed from its source:
 *
 *  - receiving (has a purchase batch): the posted quantity was received x the ordered
 *    row's BaseQty, so it is re-derived in the ordered unit and converted; the ledger
 *    entry, its batch and the product_inventory_stocks balance move to the stock row.
 *  - POS sales ("Sale #..."): posted in the row's own unit to the ledger only, so the
 *    entry is converted and moved.
 *
 * Anything else is listed for manual review and left alone. Updates only - nothing is
 * deleted - and the original values are written to storage/logs first.
 */
class RekeyBaseStockRows extends Command
{
    protected $signature = 'stock:rekey-base-rows {--item= : Only this ItemCode} {--apply : Write the corrections}';

    protected $description = 'Move stock posted to a non-stock base row onto the item\'s SAP base-unit row. Dry run unless --apply.';

    public function handle(): int
    {
        $this->line('Database: '.DB::selectOne('SELECT DB_NAME() AS n')->n.($this->option('apply') ? ' (APPLY)' : ' (dry run)'));

        $items = SAPMasterfile::withoutEntityScope()
            ->whereColumn('BaseUOM', 'AltUOM')
            ->when($this->option('item'), fn ($q, $item) => $q->where('ItemCode', $item))
            ->groupBy('entity_id', 'ItemCode')
            ->havingRaw('COUNT(*) > 1')
            ->get(['entity_id', 'ItemCode']);

        $plan = [];
        $manual = [];

        foreach ($items as $item) {
            $stockUnit = ItemStockUnit::forItem($item->ItemCode, (int) $item->entity_id);
            $rows = SAPMasterfile::withoutEntityScope()
                ->where('entity_id', $item->entity_id)->where('ItemCode', $item->ItemCode)->get();

            foreach ($rows->filter(fn ($row) => strcasecmp(trim($row->AltUOM), trim($row->BaseUOM)) === 0) as $row) {
                $target = $stockUnit->stockRowFor($row->AltUOM);
                if (! $target || $target->id === $row->id) {
                    continue;
                }

                $entries = DB::table('product_inventory_stock_managers')->where('product_inventory_id', $row->id)->orderBy('id')->get();

                foreach ($entries as $entry) {
                    $change = $this->correction($entry, $row, $rows, $stockUnit);

                    if ($change === null) {
                        $manual[] = [$item->ItemCode, $entry->id, $entry->store_branch_id, $entry->action, (float) $entry->quantity.' '.$row->AltUOM, mb_substr((string) $entry->remarks, 0, 60)];

                        continue;
                    }

                    $plan[] = $change + ['entry' => $entry, 'from' => $row, 'to' => $stockUnit->stockRowFor($change['unit'])];
                }
            }
        }

        if ($plan) {
            $this->table(['Item', 'Ledger', 'Branch', 'Source', 'Was', 'Becomes'], array_map(fn ($c) => [
                $c['from']->ItemCode, $c['entry']->id, $c['entry']->store_branch_id, $c['source'],
                $this->qty($c['entry']->quantity).' on row '.$c['from']->id.' ('.$c['from']->AltUOM.')',
                $this->qty($c['quantity']).' '.$c['to']->AltUOM.' on row '.$c['to']->id,
            ], $plan));
        } else {
            $this->info('Nothing to move.');
        }

        if ($manual) {
            $this->warn('Needs manual review (unknown source, left alone):');
            $this->table(['Item', 'Ledger', 'Branch', 'Action', 'Quantity', 'Remarks'], $manual);
        }

        if (! $plan || ! $this->option('apply')) {
            if ($plan) {
                $this->comment('Dry run - nothing written. Re-run with --apply to correct these entries.');
            }

            return self::SUCCESS;
        }

        $this->apply($plan);
        $this->info(count($plan).' ledger entr'.(count($plan) === 1 ? 'y' : 'ies').' corrected.');

        return self::SUCCESS;
    }

    /** What the entry should have been, or null when its source is not recognised. */
    private function correction(object $entry, object $row, $rows, ItemStockUnit $stockUnit): ?array
    {
        if ($entry->purchase_item_batch_id) {
            $batch = DB::table('purchase_item_batches')->find($entry->purchase_item_batch_id);
            $orderItem = $batch?->store_order_item_id ? DB::table('store_order_items')->find($batch->store_order_item_id) : null;
            if (! $orderItem || ! $orderItem->uom) {
                return null;
            }

            // Receiving posted received x BaseQty of the ordered unit's row.
            $orderedRow = $rows->first(fn ($r) => strcasecmp(trim($r->AltUOM), trim($orderItem->uom)) === 0);
            $oldFactor = $orderedRow && (float) $orderedRow->BaseQty > 0 ? (float) $orderedRow->BaseQty : 1.0;
            $newFactor = $stockUnit->factor($orderItem->uom);
            if (! $newFactor || ! $stockUnit->stockRowFor($orderItem->uom)) {
                return null;
            }

            return ['source' => 'receiving', 'unit' => $orderItem->uom, 'batch' => $batch,
                'quantity' => (float) $entry->quantity / $oldFactor * $newFactor];
        }

        if (str_starts_with((string) $entry->remarks, 'Sale #')) {
            return ['source' => 'POS sale', 'unit' => $row->AltUOM, 'batch' => null,
                'quantity' => (float) $entry->quantity * $stockUnit->factor($row->AltUOM)];
        }

        return null;
    }

    private function apply(array $plan): void
    {
        $backup = storage_path('logs/stock-rekey-base-rows-'.now()->format('Ymd-His').'.json');
        file_put_contents($backup, json_encode(array_map(fn ($c) => [
            'ledger' => $c['entry'],
            'batch' => $c['batch'],
            'stock_from' => DB::table('product_inventory_stocks')->where('product_inventory_id', $c['from']->id)->where('store_branch_id', $c['entry']->store_branch_id)->first(),
            'stock_to' => DB::table('product_inventory_stocks')->where('product_inventory_id', $c['to']->id)->where('store_branch_id', $c['entry']->store_branch_id)->first(),
            'new_quantity' => $c['quantity'],
            'to_row' => $c['to']->id,
        ], $plan), JSON_PRETTY_PRINT));
        $this->line("Original values saved to {$backup}");

        DB::transaction(function () use ($plan) {
            foreach ($plan as $c) {
                $entry = $c['entry'];
                $quantity = $c['quantity'];
                $unitCost = $quantity > 0 ? (float) $entry->total_cost / $quantity : $entry->unit_cost;

                DB::table('product_inventory_stock_managers')->where('id', $entry->id)->update([
                    'product_inventory_id' => $c['to']->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ]);

                if (! $c['batch']) {
                    continue; // POS sales touch the ledger only.
                }

                $batch = $c['batch'];
                $scale = (float) $batch->quantity > 0 ? $quantity / (float) $batch->quantity : 1.0;
                DB::table('purchase_item_batches')->where('id', $batch->id)->update([
                    'product_inventory_id' => $c['to']->id,
                    'quantity' => $quantity,
                    'remaining_quantity' => (float) $batch->remaining_quantity * $scale,
                    'unit_cost' => $unitCost,
                ]);

                // Receiving added the entry to the old row's balance; carry it across.
                $branchId = $entry->store_branch_id;
                $from = DB::table('product_inventory_stocks')->where('product_inventory_id', $c['from']->id)->where('store_branch_id', $branchId);
                if ($from->exists()) {
                    $from->update([
                        'quantity' => DB::raw('quantity - '.(float) $entry->quantity),
                        'recently_added' => DB::raw('recently_added - '.(float) $entry->quantity),
                    ]);
                }

                $to = DB::table('product_inventory_stocks')->where('product_inventory_id', $c['to']->id)->where('store_branch_id', $branchId);
                if ($to->exists()) {
                    $to->update([
                        'quantity' => DB::raw('quantity + '.$quantity),
                        'recently_added' => DB::raw('recently_added + '.$quantity),
                    ]);
                } else {
                    DB::table('product_inventory_stocks')->insert([
                        'product_inventory_id' => $c['to']->id, 'store_branch_id' => $branchId,
                        'quantity' => $quantity, 'recently_added' => $quantity, 'used' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function qty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
