<?php

namespace App\Services;

use App\Models\MonthEndCountItem;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Support\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Reconcile the entire count sequence, never an isolated older adjustment. */
class MonthEndHistoryRepair
{
    private function signed($row): float
    {
        if (!in_array($row->action, ['add','add_quantity','out'], true)) {
            throw new \RuntimeException("Unknown stock action on movement {$row->id}.");
        }
        return (in_array($row->action, ['add','add_quantity'], true) ? 1 : -1) * (float) $row->quantity;
    }

    public function run(int $branchId, int $productId, bool $apply, string $evidencePath): array
    {
        return DB::transaction(function () use ($branchId, $productId, $apply, $evidencePath) {
            StoreBranch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            $product = SAPMasterfile::findOrFail($productId);
            $targets = SAPMasterfile::where('ItemCode', $product->ItemCode)->whereColumn('BaseUOM','AltUOM')->get();
            if ($targets->count() !== 1 || (int)$targets->first()->id !== $productId) {
                throw new \RuntimeException('Missing or ambiguous base stock item; requires unit reconciliation.');
            }
            $relatedIds = SAPMasterfile::where('ItemCode', $product->ItemCode)->pluck('id');
            $counts = MonthEndCountItem::where('branch_id',$branchId)->whereIn('sap_masterfile_id',$relatedIds)
                ->where('status','level2_approved')->with('schedule')->lockForUpdate()->get()->groupBy('month_end_schedule_id');
            // SQL Server's lockForUpdate includes HOLDLOCK, protecting this ledger range during apply.
            $movements = ProductInventoryStockManager::where('product_inventory_id',$productId)->where('store_branch_id',$branchId)
                ->orderBy('transaction_date')->orderBy('id')->lockForUpdate()->get();
            $originals = []; $repairs = []; $seenSchedules = []; $repairRows = [];
            foreach ($movements as $movement) {
                $this->signed($movement);
                if (preg_match('/MEC_(?:REPAIR|CHAIN)::(\d+);/', (string)$movement->remarks, $match)) {
                    $repairs[(int)$match[1]] = ($repairs[(int)$match[1]] ?? 0) + $this->signed($movement);
                    $repairRows[] = $movement;
                    continue;
                }
                if (preg_match('/MEC_REF::(\d+),(\d+)/', (string)$movement->remarks, $match)) {
                    if ((int)$match[2] !== $branchId || !$movement->is_stock_adjustment || !$movement->is_stock_adjustment_approved) {
                        throw new \RuntimeException("Invalid MEC reference or approval flags on movement {$movement->id}.");
                    }
                    $scheduleId = (int)$match[1];
                    if (isset($seenSchedules[$scheduleId])) throw new \RuntimeException("Multiple original MEC movements for schedule {$scheduleId}.");
                    $items = $counts->get($scheduleId);
                    if (!$items || $items->isEmpty()) throw new \RuntimeException("No approved count for movement {$movement->id}.");
                    if ($items->contains(fn ($item) => !$item->schedule || !$item->schedule->calculated_date || !is_numeric($item->total_qty)
                        || !is_finite((float)$item->total_qty) || $item->total_qty < 0 || strcasecmp(trim($item->uom),trim($product->BaseUOM)) !== 0)) {
                        throw new \RuntimeException("Count quantities, units or schedule need review: {$scheduleId}.");
                    }
                    // A partly approved group cannot establish a trustworthy complete count.
                    if (MonthEndCountItem::where('branch_id',$branchId)->where('month_end_schedule_id',$scheduleId)
                        ->whereIn('sap_masterfile_id',$relatedIds)->where('status','!=','level2_approved')->exists()) {
                        throw new \RuntimeException("Partly approved count for schedule {$scheduleId}.");
                    }
                    $seenSchedules[$scheduleId] = true;
                    $originals[$movement->id] = ['schedule_id'=>$scheduleId, 'count'=>(float)$items->sum('total_qty'),
                        'count_item_ids'=>$items->pluck('id')->all(), 'date'=>$movement->transaction_date->toDateString()];
                } elseif (preg_match('/month\s*end|\bMEC\b/i',(string)$movement->remarks)) {
                    throw new \RuntimeException("Unlinked legacy month-end movement {$movement->id}; no reliable schedule reference.");
                }
            }
            if ($counts->keys()->diff(array_keys($seenSchedules))->isNotEmpty()) {
                throw new \RuntimeException('An approved count has no linked movement (possibly a legacy zero adjustment); reconstruct its approval boundary before repairing this item.');
            }
            foreach ($repairRows as $row) {
                preg_match('/MEC_(?:REPAIR|CHAIN)::(\d+);/', $row->remarks, $match);
                $anchor = $originals[(int)$match[1]] ?? null;
                if (!$anchor || $row->transaction_date->toDateString() !== $anchor['date']) throw new \RuntimeException('Existing repair has an invalid anchor or effective date.');
            }
            if (!$originals) throw new \RuntimeException('No linked approved count to repair.');

            $actual = 0.0; $reconstructed = 0.0; $plan = [];
            foreach ($movements as $movement) {
                $actual += $this->signed($movement);
                if (preg_match('/MEC_(?:REPAIR|CHAIN)::\d+;/',(string)$movement->remarks)) continue;
                $reconstructed += $this->signed($movement);
                if (isset($originals[$movement->id])) {
                    $anchor = $originals[$movement->id];
                    // Existing repair rows belong logically at their original count boundary,
                    // even though their audit IDs are newer than other same-day movements.
                    $reconstructed += $repairs[$movement->id] ?? 0;
                    $delta = StockQuantity::adjustment($anchor['count'],$reconstructed);
                    $plan[] = $anchor + ['original_id'=>$movement->id, 'balance_before_correction'=>StockQuantity::normalize($reconstructed),
                        'correction'=>$delta, 'original'=>$movement->getAttributes()];
                    $reconstructed += (float)$delta;
                }
            }
            $changes = array_values(array_filter($plan, fn ($p) => !StockQuantity::isZero($p['correction'])));
            $result = ['entity_id'=>$product->entity_id,'branch_id'=>$branchId,'product_id'=>$productId,'item_code'=>$product->ItemCode,
                'status'=>$changes ? ($apply ? 'applied' : 'would_repair') : 'unchanged',
                'before'=>StockQuantity::normalize($actual),'after'=>StockQuantity::normalize($reconstructed),
                'count_boundaries'=>count($plan),'changes'=>$changes];
            if ($apply && $changes) {
                if (!Storage::put($evidencePath,json_encode(['database'=>DB::connection()->getDatabaseName(),'phase'=>'planned','plan'=>$result,'all_boundaries'=>$plan],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))) {
                    throw new \RuntimeException('Could not save before-state evidence.');
                }
                $ids = [];
                foreach ($changes as $change) {
                    $row = ProductInventoryStockManager::create([
                        'product_inventory_id'=>$productId,'store_branch_id'=>$branchId,'quantity'=>StockQuantity::absolute($change['correction']),
                        'action'=>StockQuantity::isPositive($change['correction']) ? 'add' : 'out', 'transaction_date'=>$change['date'],
                        'unit_cost'=>0,'total_cost'=>0,'is_stock_adjustment'=>true,'is_stock_adjustment_approved'=>true,
                        'remarks'=>"MEC_CHAIN::{$change['original_id']}; Reconcile count to {$change['count']} at approval; evidence {$evidencePath}||MEC_REF::{$change['schedule_id']},{$branchId}",
                    ]);
                    $ids[] = $row->id;
                }
                $stock = app(MonthEndStockAdjustment::class);
                $stock->refreshCache($productId,$branchId);
                if ($stock->balance($productId,$branchId) !== $result['after']) throw new \RuntimeException('Final ledger verification failed; item rolled back.');
                $result['movement_ids'] = $ids;
                $result['evidence'] = $evidencePath;
            }
            return $result;
        });
    }
}
