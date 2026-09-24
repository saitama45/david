<?php

namespace App\Services;

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Support\ItemStockUnit;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\User;
use App\Support\StoreReceiptIdentity as Identity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StoreTransactionReceiptProcessor
{
    protected array $skippedRows = [];
    protected int $createdCount = 0;
    private bool $preview = false;
    protected array $storeBranchIds = [];

    public function __construct(protected ?User $actor = null, protected string $source = 'manual', protected ?int $replacementId = null, protected ?int $importLogId = null) {}

    public function previewReceiptGroup(Collection $rows): bool
    {
        $this->preview = true;
        try { return $this->processReceiptGroup($rows) !== null; }
        finally { $this->preview = false; }
    }

    /** Both channels take the same branch lock before checking the day and posting. */
    public function processReceiptGroup(Collection $rows): ?StoreTransaction
    {
        try {
            if ($rows->isEmpty()) throw new \InvalidArgumentException('Receipt has no lines.');
            $sale = DB::transaction(function () use ($rows) {
                $ledger = new SalesPostingLedger;
                $ledger->requireReady();
                $first = $rows->first();
                $branch = (isset($first['__branch_id']) ? StoreBranch::whereKey($first['__branch_id'])
                    : StoreBranch::where('location_code', trim($first['branch'] ?? '')))->lockForUpdate()->first();
                $ledger->requireReady();
                if (!$branch) throw new \InvalidArgumentException('Branch not found.');
                $this->storeBranchIds[$branch->id] = $branch->id;
                if ($this->actor && !in_array($branch->id, (new SalesImportStatus)->branchIds($this->actor))) {
                    throw new \InvalidArgumentException('You are not authorized to import sales for this store.');
                }
                $date = Identity::date($first['date'] ?? null);
                [$terminal, $receipt] = Identity::parts($first['receipt_no'] ?? '', $first['tm'] ?? '');
                if ($this->source === 'manual' && $ledger->syncedDay($branch->entity_id, $branch->id, $date)) {
                    throw new \InvalidArgumentException("Manual import blocked: this store already has automated sales for {$date}. Use POS reconciliation for missing receipts.");
                }
                $key = Identity::key($branch->entity_id, $branch->id, $date, $receipt, $terminal);
                $existing = $ledger->existing($branch->id, $date, $receipt, $terminal);
                if (DB::table('sales_postings')->where('receipt_key', $key)->exists()
                    || ($existing && $existing->id !== $this->replacementId)) {
                    throw new \InvalidArgumentException('Receipt already exists for this store, date and terminal; no additional deduction.');
                }
                $items = [];
                foreach ($rows as $row) {
                    if (Identity::date($row['date'] ?? null) !== $date
                        || Identity::parts($row['receipt_no'] ?? '', $row['tm'] ?? '') !== [$terminal, $receipt]
                        || ($row['__branch_id'] ?? $row['branch'] ?? '') !== ($first['__branch_id'] ?? $first['branch'] ?? '')) {
                        throw new \InvalidArgumentException('Mixed store, date or terminal within a receipt.');
                    }
                    foreach (['qty','base_qty'] as $field) {
                        $n = $row[$field] ?? null;
                        if (!is_numeric($n) || !is_finite((float) $n) || $n <= 0 || floor((float) $n) != $n || $n > 2147483647) {
                            throw new \InvalidArgumentException('Quantity and base quantity must be positive whole numbers within the supported range.');
                        }
                    }
                    foreach (['price','discount','line_total','net_total'] as $field) {
                        if (!isset($row[$field]) || !is_numeric($row[$field]) || !is_finite((float) $row[$field])) {
                            throw new \InvalidArgumentException("Invalid numeric {$field}.");
                        }
                    }
                    if ($row['price'] < 0 || $row['line_total'] < 0 || $row['net_total'] < 0) {
                        throw new \InvalidArgumentException('Negative sales amounts require a correction, not a sales import.');
                    }
                    $take = strtoupper(trim((string) ($row['take_out'] ?? '')));
                    if (!in_array($take, ['', 'N','NO','FALSE','0','Y','YES','TRUE','1','TAKE OUT','TAKEOUT'])) {
                        throw new \InvalidArgumentException('Invalid take-out value.');
                    }
                    $take = in_array($take, ['Y','YES','TRUE','1','TAKE OUT','TAKEOUT']);
                    $code = strtoupper(trim((string) ($row['product_id'] ?? '')));
                    $itemKey = $code.'|'.(int) $take;
                    if (!isset($items[$itemKey])) {
                        $pos = POSMasterfile::whereRaw('UPPER(POSCode) = ?', [$code])->get();
                        if ($pos->count() !== 1) throw new \InvalidArgumentException("Missing or ambiguous POS masterfile: {$code}.");
                        $items[$itemKey] = ['pos' => $pos->first(), 'qty' => 0, 'base_qty' => 0, 'discount' => 0,
                            'line_total' => 0, 'net_total' => 0, 'price' => (float) $row['price'], 'take_out' => $take];
                    }
                    foreach (['qty','base_qty','discount','line_total','net_total'] as $field) $items[$itemKey][$field] += (float) $row[$field];
                }
                $ingredients = [];
                foreach ($items as $item) {
                    if ($item['qty'] > 2147483647 || $item['base_qty'] > 2147483647) throw new \InvalidArgumentException('Aggregated quantity exceeds supported range.');
                    $pos = $item['pos'];
                    $bom = POSMasterfileBOM::where('POSCode', $pos->POSCode)->get();
                    $exempt = config('sales_posting.non_inventory_products.'.$branch->entity_id, []);
                    if ($bom->isEmpty() && !in_array($pos->POSCode, $exempt, true)) {
                        throw new \InvalidArgumentException("Missing BOM for {$pos->POSCode}. Configure its recipe or explicitly classify it as non-inventory.");
                    }
                    foreach ($bom as $recipe) {
                        // Stock lives on the item's SAP base-unit row; the recipe unit converts into it.
                        $stockUnit = ItemStockUnit::forItem($recipe->ItemCode, (int) $branch->entity_id);
                        $sap = $stockUnit->stockRowFor($recipe->BOMUOM);
                        if (!$sap) throw new \InvalidArgumentException("Missing base-stock masterfile: {$recipe->ItemCode}.");
                        $factor = $stockUnit->factor($recipe->BOMUOM);
                        if (!$factor) throw new \InvalidArgumentException("Missing unit conversion from {$recipe->BOMUOM} to a stock unit for {$recipe->ItemCode}.");
                        if (!is_numeric($recipe->BOMQty) || (float) $recipe->BOMQty <= 0 || !is_finite((float) $recipe->BOMQty)) {
                            throw new \InvalidArgumentException("Invalid BOM quantity for {$recipe->ItemCode}.");
                        }
                        $qty = (float) $recipe->BOMQty * $factor * $item['qty'];
                        $cost = (float) $recipe->UnitCost / $factor;
                        if (!is_finite($qty) || !is_finite($cost) || $cost < 0) throw new \InvalidArgumentException('Invalid ingredient quantity or cost.');
                        $ingredients[] = ['sap' => $sap, 'qty' => $qty, 'cost' => $cost, 'pos' => $pos->POSCode,
                            'bom_qty' => $recipe->BOMQty, 'bom_uom' => $recipe->BOMUOM];
                    }
                }
                if ($this->preview) return new StoreTransaction;
                $transaction = StoreTransaction::create(['store_branch_id' => $branch->id, 'order_date' => $date,
                    'posted' => $first['posted'] ?? '', 'tim_number' => $terminal, 'receipt_number' => $receipt]);
                foreach ($items as $item) {
                    $transaction->store_transaction_items()->create(['product_id' => $item['pos']->id,
                        'quantity' => $item['qty'], 'base_quantity' => $item['base_qty'], 'price' => $item['price'],
                        'discount' => $item['discount'], 'line_total' => $item['line_total'], 'net_total' => $item['net_total'], 'take_out' => $item['take_out']]);
                }
                $snapshots = [];
                foreach ($ingredients as $ingredient) {
                    $movement = ProductInventoryStockManager::create(['product_inventory_id' => $ingredient['sap']->id,
                        'store_branch_id' => $branch->id, 'cost_center_id' => null, 'quantity' => $ingredient['qty'],
                        'action' => 'out', 'unit_cost' => $ingredient['cost'], 'total_cost' => $ingredient['qty'] * $ingredient['cost'],
                        'transaction_date' => $date,
                        'remarks' => "Sale #{$transaction->id}, terminal {$terminal}, receipt {$receipt}; {$ingredient['pos']}: BOM {$ingredient['bom_qty']} {$ingredient['bom_uom']}"]);
                    $snapshots[] = (array) DB::table('product_inventory_stock_managers')->where('id', $movement->id)
                        ->first(['id','entity_id','product_inventory_id','store_branch_id','quantity','action','unit_cost','total_cost','transaction_date','remarks']);
                }
                DB::table('sales_postings')->insert(['entity_id' => $branch->entity_id, 'store_branch_id' => $branch->id,
                    'business_date' => $date, 'receipt_key' => $key, 'store_transaction_id' => $transaction->id,
                    'source' => $this->source, 'import_log_id' => $this->importLogId, 'user_id' => $this->actor?->id, 'rows_hash' => $ledger->rowsHash($rows->all()),
                    'destination_hash' => $ledger->destinationHash($transaction->id), 'movements' => json_encode($snapshots, JSON_THROW_ON_ERROR),
                    'pos_verified_at' => $this->source === 'pos' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                if ($this->importLogId) DB::table('import_logs')->where('id', $this->importLogId)->update(['last_heartbeat_at' => now()]);
                return $transaction;
            });
            if (!$this->preview) $this->createdCount++;
            return $sale;
        } catch (\Throwable $e) {
            $this->addSkippedGroup($rows, $e->getMessage());
            return null;
        }
    }

    protected function addSkippedGroup(Collection $rows, string $reason): void
    {
        foreach ($rows as $row) $this->skippedRows[] = ['row_number' => $row['__row_number'] ?? null,
            'reason' => $reason, 'item_code' => $row['product_id'] ?? '', 'item_description' => $row['product_name'] ?? '',
            'uom' => $row['uom'] ?? '', 'store_code' => $row['branch'] ?? '', 'receipt_number' => $row['receipt_no'] ?? '',
            'qty' => $row['qty'] ?? '', 'date_of_sales' => $row['date'] ?? ''];
    }

    public function getSkippedRows(): array { return $this->skippedRows; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getStoreBranchIds(): array { return array_values($this->storeBranchIds); }
}
