<?php

namespace App\Console\Commands;

use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Services\MonthEndStockAdjustment;
use App\Support\EntityContext;
use App\Support\StockQuantity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RepairMonthEndStock extends Command
{
    protected $signature = 'stock:repair-month-end {movement : Original MEC movement ID} {--apply : Append audited reversal and corrected count adjustment}';
    protected $description = 'Preview or repair one legacy month-end adjustment against the ledger, preserving its approval date';

    public function handle(MonthEndStockAdjustment $stock): int
    {
        try {
            $original = ProductInventoryStockManager::withoutEntityScope()->findOrFail($this->argument('movement'));
            return app(EntityContext::class)->runAs((int) $original->entity_id, function () use ($stock, $original) {
                return DB::transaction(function () use ($stock, $original) {
                    StoreBranch::whereKey($original->store_branch_id)->lockForUpdate()->firstOrFail();
                    $original->refresh();
                    $marker = 'MEC_REPAIR::'.$original->id.';';
                    if (ProductInventoryStockManager::where('store_branch_id', $original->store_branch_id)->where('remarks', 'like', '%'.$marker.'%')->exists()) {
                        $this->info('Already repaired; no changes.');
                        return self::SUCCESS;
                    }
                    if (!$original->is_stock_adjustment || !$original->is_stock_adjustment_approved
                        || !in_array($original->action, ['add','add_quantity','out'], true)
                        || !preg_match('/MEC_REF::(\d+),(\d+)/', $original->remarks, $match)
                        || (int) $match[2] !== (int) $original->store_branch_id) throw new \RuntimeException('Not a supported approved MEC movement.');
                    $schedule = MonthEndSchedule::findOrFail($match[1]);
                    if (!$schedule->calculated_date) throw new \RuntimeException('Count date is missing.');
                    $date = $original->transaction_date->toDateString();
                    $product = SAPMasterfile::findOrFail($original->product_inventory_id);
                    $base = SAPMasterfile::where('ItemCode', $product->ItemCode)->whereColumn('BaseUOM','AltUOM')->get();
                    if ($base->count() !== 1 || $base->first()->id !== $product->id) throw new \RuntimeException('Ambiguous or non-base stock item.');
                    $productIds = SAPMasterfile::where('ItemCode', $product->ItemCode)->pluck('id');
                    $items = MonthEndCountItem::where('branch_id', $original->store_branch_id)->where('month_end_schedule_id', $schedule->id)
                        ->whereIn('sap_masterfile_id', $productIds)->get();
                    if ($items->isEmpty() || $items->contains(fn ($i) => $i->status !== 'level2_approved'
                        || !is_numeric($i->total_qty) || $i->total_qty < 0 || strcasecmp(trim($i->uom), trim($product->BaseUOM)) !== 0)) {
                        throw new \RuntimeException('Count is missing, unapproved or requires unit reconciliation.');
                    }
                    $later = MonthEndCountItem::where('branch_id', $original->store_branch_id)->whereIn('sap_masterfile_id', $productIds)
                        ->where('status','level2_approved')->whereHas('schedule', fn ($q) => $q->whereDate('calculated_date', '>', $schedule->calculated_date->toDateString()))->exists();
                    if ($later) throw new \RuntimeException('A later approved count exists; reconcile the count chain together.');
                    $ref = "MEC_REF::{$schedule->id},{$original->store_branch_id}";
                    $matching = ProductInventoryStockManager::where('store_branch_id', $original->store_branch_id)->where('product_inventory_id',$product->id)
                        ->where('remarks','like','%||'.$ref)->count();
                    if ($matching !== 1) throw new \RuntimeException('Multiple or ambiguous original MEC movements.');
                    $signedOriginal = in_array($original->action, ['add','add_quantity'], true) ? (float) $original->quantity : -(float) $original->quantity;
                    // Reconstruct the original history position; preserve movements later on the same day.
                    $baseline = (float) ProductInventoryStockManager::where('product_inventory_id', $product->id)->where('store_branch_id', $original->store_branch_id)
                        ->where(fn ($q) => $q->whereDate('transaction_date', '<', $date)->orWhere(fn ($q) => $q->whereDate('transaction_date', '=', $date)->where('id', '<', $original->id)))
                        ->selectRaw("COALESCE(SUM(CASE WHEN action IN ('add','add_quantity') THEN quantity WHEN action IN ('out','deduct','log_usage') THEN -quantity ELSE 0 END),0) AS balance")->value('balance');
                    $count = StockQuantity::normalize($items->sum('total_qty'));
                    $delta = StockQuantity::adjustment($count, $baseline);
                    $before = $stock->balance($product->id, $original->store_branch_id);
                    $plan = ['database' => DB::connection()->getDatabaseName(), 'original' => $original->getAttributes(),
                        'count_item_ids' => $items->pluck('id')->all(), 'scheduled_count_date' => $schedule->calculated_date->toDateString(),
                        'effective_approval_date' => $date, 'count_quantity' => $count,
                        'ledger_before_count_excluding_original' => $baseline, 'corrected_adjustment' => $delta,
                        'current_ledger_soh' => $before, 'corrected_current_soh' => StockQuantity::normalize((float)$before - $signedOriginal + (float)$delta)];
                    $this->line(json_encode($plan, JSON_PRETTY_PRINT));
                    if (!$this->option('apply')) { $this->info('Preview only; no stock changes.'); return self::SUCCESS; }
                    $path = 'stock-repairs/mec-'.$original->id.'-'.now()->format('YmdHis').'.json';
                    if (!Storage::put($path, json_encode($plan, JSON_PRETTY_PRINT))) throw new \RuntimeException('Could not save repair evidence.');
                    ProductInventoryStockManager::create([
                        'product_inventory_id' => $product->id, 'store_branch_id' => $original->store_branch_id,
                        'quantity' => $original->quantity, 'action' => $signedOriginal > 0 ? 'out' : 'add',
                        'transaction_date' => $original->transaction_date->toDateString(), 'unit_cost' => 0, 'total_cost' => 0,
                        'is_stock_adjustment' => true, 'is_stock_adjustment_approved' => true,
                        'remarks' => "{$marker} Reversal of legacy MEC movement #{$original->id}; original retained; evidence {$path}||{$ref}",
                    ]);
                    $stock->post($product->id, $original->store_branch_id, $count, $date,
                        "{$marker} Corrected count {$count} at approval {$date}; replaces legacy movement #{$original->id}||{$ref}", StockQuantity::normalize($baseline));
                    $this->info('Repair committed as new movements; original and approved count retained.');
                    return self::SUCCESS;
                });
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
