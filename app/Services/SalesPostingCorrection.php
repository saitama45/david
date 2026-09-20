<?php

namespace App\Services;

use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SalesPostingCorrection
{
    /** Correct recorded consumption, not a financial refund or physical return. */
    public function replace(StoreTransaction $sale, array $rows, User $actor, string $reason): void
    {
        if (!$actor->can('edit store transactions')) abort(403);
        if (strlen(trim($reason)) < 10) throw new \InvalidArgumentException('Explain the sales correction in at least 10 characters.');
        DB::transaction(function () use ($sale, $rows, $actor, $reason) {
            $ledger = new SalesPostingLedger;
            $ledger->requireReady();
            StoreBranch::whereKey($sale->store_branch_id)->lockForUpdate()->firstOrFail();
            $sale->refresh();
            if (!in_array($sale->store_branch_id, (new SalesImportStatus)->branchIds($actor))) abort(403);
            $old = DB::table('sales_postings')->where('store_transaction_id', $sale->id)->first();
            if (!$old || !$ledger->intact($old)) {
                throw new \InvalidArgumentException('This sale lacks intact, linked inventory movements. Reconcile its original stock history before correction.');
            }
            $first = $rows[0] ?? [];
            if (!hash_equals($old->receipt_key, \App\Support\StoreReceiptIdentity::key($sale->entity_id,
                (int) ($first['__branch_id'] ?? 0), $first['date'] ?? null, $first['receipt_no'] ?? '', $first['tm'] ?? ''))) {
                throw new \InvalidArgumentException('A correction cannot change the store, business date, terminal or receipt number.');
            }
            $originalItems = $sale->store_transaction_items()->get()->toJson();
            $reversals = [];
            foreach (json_decode($old->movements, true, 512, JSON_THROW_ON_ERROR) as $movement) {
                $reversal = ProductInventoryStockManager::create([
                    'product_inventory_id' => $movement['product_inventory_id'], 'store_branch_id' => $sale->store_branch_id,
                    'quantity' => $movement['quantity'], 'action' => 'add', 'unit_cost' => $movement['unit_cost'],
                    'total_cost' => $movement['total_cost'], 'transaction_date' => $sale->order_date->format('Y-m-d'),
                    'remarks' => "Correction of sale #{$sale->id}; reverses movement #{$movement['id']}: {$reason}",
                ]);
                $reversals[] = $reversal->id;
            }
            DB::table('sales_postings')->where('id', $old->id)->delete();
            $processor = new StoreTransactionReceiptProcessor($actor, 'correction', $sale->id);
            $replacement = $processor->processReceiptGroup(collect($rows));
            if (!$replacement) throw new \InvalidArgumentException(implode(' | ', array_unique(array_column($processor->getSkippedRows(), 'reason'))));
            $new = DB::table('sales_postings')->where('store_transaction_id', $replacement->id)->first();
            if ($new->receipt_key !== $old->receipt_key) throw new \InvalidArgumentException('A correction cannot change the store, business date, terminal or receipt number.');
            $newMovements = json_decode($new->movements, true, 512, JSON_THROW_ON_ERROR);
            foreach ($newMovements as &$movement) {
                $movement['remarks'] = preg_replace('/^Sale #[0-9]+,/', "Sale #{$sale->id},", $movement['remarks']);
                DB::table('product_inventory_stock_managers')->where('id', $movement['id'])->update(['remarks' => $movement['remarks']]);
            }
            unset($movement);
            $sale->store_transaction_items()->delete();
            $replacement->store_transaction_items()->update(['store_transaction_id' => $sale->id]);
            DB::table('sales_postings')->where('id', $new->id)->update([
                'store_transaction_id' => $sale->id, 'movements' => json_encode($newMovements, JSON_THROW_ON_ERROR), 'destination_hash' => $ledger->destinationHash($sale->id),
                'pos_verified_at' => $old->pos_verified_at, 'import_log_id' => $old->import_log_id,
            ]);
            $replacement->delete();
            DB::table('sales_posting_corrections')->insert([
                'entity_id' => $sale->entity_id, 'store_transaction_id' => $sale->id, 'user_id' => $actor->id,
                'reason' => $reason, 'original_posting' => json_encode($old, JSON_THROW_ON_ERROR),
                'original_items' => $originalItems, 'reversal_ids' => json_encode($reversals),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
