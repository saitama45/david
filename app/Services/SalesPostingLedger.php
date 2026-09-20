<?php

namespace App\Services;

use App\Models\StoreTransaction;
use App\Support\StoreReceiptIdentity as Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesPostingLedger
{
    public function requireReady(): void
    {
        if (!app(\App\Support\EntityContext::class)->has()) throw new \RuntimeException('An active entity is required for sales posting.');
        if (!Schema::hasTable('sales_postings') || !Schema::hasTable('sales_posting_corrections') || !Schema::hasTable('pos_sync_exceptions')) {
            throw new \RuntimeException('Apply the sales posting controls migration before importing sales.');
        }
        if (config('sales_posting.paused') || \Illuminate\Support\Facades\Cache::get('sales-posting:paused', false)) {
            throw new \RuntimeException('Sales posting is paused. No inventory changes were made.');
        }
    }

    public function syncedDay(int $entity, int $branch, string $date): bool
    {
        if (Schema::hasTable('sales_postings') && DB::table('sales_postings')
            ->where('entity_id', $entity)->where('store_branch_id', $branch)->whereDate('business_date', $date)
            ->whereNotNull('pos_verified_at')->exists()) return true;
        // Also protect receipts synchronized before the controls migration.
        return Schema::hasTable('pos_sync_receipts') && DB::table('pos_sync_receipts as p')
            ->join('store_transactions as t', 't.id', '=', 'p.store_transaction_id')
            ->where('p.entity_id', $entity)->where('p.store_branch_id', $branch)
            ->whereDate('t.order_date', $date)->exists();
    }

    public function existing(int $branch, string $date, string $receipt, string $terminal): ?StoreTransaction
    {
        $parts = Identity::parts($receipt, $terminal);
        // Normalize legacy values in PHP too: padding width was not historically fixed.
        foreach (StoreTransaction::where('store_branch_id', $branch)->whereDate('order_date', $date)->cursor() as $sale) {
            try {
                $storedTerminal = $sale->tim_number;
                if ($storedTerminal === null || trim((string) $storedTerminal) === '') {
                    if (!str_contains($sale->receipt_number, '-')) $storedTerminal = $terminal;
                }
                if (Identity::parts($sale->receipt_number, $storedTerminal) === $parts) return $sale;
            } catch (\InvalidArgumentException) {
                // A malformed legacy identity is not automatically repaired.
                if ($sale->receipt_number === $receipt) throw new \RuntimeException('Ambiguous existing receipt requires reconciliation.');
            }
        }
        return null;
    }

    public function rowsHash(array $rows): string
    {
        $items = [];
        foreach ($rows as $row) {
            $key = strtoupper(trim($row['product_id'])).'|'.(in_array(strtoupper(trim((string) ($row['take_out'] ?? ''))), ['Y','YES','TRUE','1','TAKE OUT','TAKEOUT'], true) ? '1' : '0');
            foreach (['qty','base_qty','discount','line_total','net_total'] as $field) {
                $items[$key][$field] = ($items[$key][$field] ?? 0) + (float) $row[$field];
            }
            // Preserve price variants in the comparison even when destination items aggregate.
            $items[$key]['prices'][] = sprintf('%.4F', (float) $row['price']);
        }
        ksort($items);
        foreach ($items as &$item) {
            $item['prices'] = array_values(array_unique($item['prices']));
            sort($item['prices']);
            foreach (['qty','base_qty','discount','line_total','net_total'] as $field) $item[$field] = sprintf('%.4F', $item[$field]);
        }
        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }

    public function destinationHash(int $id): string
    {
        $header = DB::table('store_transactions')->where('id', $id)->first(['entity_id','store_branch_id','order_date','tim_number','receipt_number']);
        if (!$header) return '';
        $header->order_date = substr((string) $header->order_date, 0, 10);
        $items = DB::table('store_transaction_items')->where('store_transaction_id', $id)->orderBy('id')
            ->get(['product_id','quantity','base_quantity','price','discount','line_total','net_total','take_out'])->map(function ($row) {
                return array_map(fn ($value) => sprintf('%.4F', (float) $value), (array) $row);
            })->all();
        return hash('sha256', json_encode([(array) $header, $items], JSON_THROW_ON_ERROR));
    }

    public function intact(object $posting): bool
    {
        if (!hash_equals($posting->destination_hash, $this->destinationHash($posting->store_transaction_id))) return false;
        foreach (json_decode($posting->movements, true, 512, JSON_THROW_ON_ERROR) as $expected) {
            $actual = DB::table('product_inventory_stock_managers')->where('id', $expected['id'])->first();
            if (!$actual) return false;
            foreach ($expected as $key => $value) {
                if (in_array($key, ['quantity','unit_cost','total_cost'])) {
                    if (abs((float) $actual->$key - (float) $value) > 0.000001) return false;
                } elseif ((string) $actual->$key !== (string) $value) return false;
            }
        }
        return true;
    }
}
