<?php

namespace App\Console\Commands;

use App\Models\MonthEndCountItem;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Services\MonthEndStockAdjustment;
use App\Services\SubPrepCountStock;
use App\Support\EntityContext;
use App\Support\SubPrepBreakdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Does for a count approved before 2026-11-23 what Level 2 approval now does with a counted
 * Sub-Prep (prep mix): what is inside each mix goes onto the raw materials counted with it,
 * and the mix's own stock row is brought to 0 (SubPrepCountStock). Dry run unless --apply.
 *
 * Per store, only the latest final-approved count of its mixes is read, and only while each
 * mix row still holds exactly what that count put there; anything else is listed for review.
 * A store whose count already carries the SUBPREP_REF marker is done. Inserts only - nothing
 * is deleted or changed - and each movement is dated on the day the count was approved.
 */
class BreakDownSubPrepStock extends Command
{
    protected $signature = 'stock:break-down-sub-preps {--apply : Write the movements}';

    protected $description = 'Move the stock a final-approved month end count put on a Sub-Prep (prep mix) onto the raw materials counted with it. Dry run unless --apply.';

    public function handle(SubPrepCountStock $subPrepStock, MonthEndStockAdjustment $stock): int
    {
        $apply = (bool) $this->option('apply');
        $this->line('Database: '.DB::selectOne('SELECT DB_NAME() AS n')->n.($apply ? ' (APPLY)' : ' (dry run)'));

        if ($apply) {
            // A console run writes no audits unless told to; this correction should leave them.
            config(['audit.console' => true]);
        }

        $entityIds = DB::table('pos_masterfiles')
            ->whereRaw('UPPER(LTRIM(RTRIM([Category]))) = ?', [\App\Models\POSMasterfile::SUB_PREP_CATEGORY])
            ->whereNotNull('entity_id')->distinct()->pluck('entity_id')->map(fn ($id) => (int) $id);
        $planned = [];
        $review = [];

        foreach ($entityIds as $entityId) {
            app(EntityContext::class)->runAs($entityId, function () use ($entityId, $subPrepStock, $stock, $apply, &$planned, &$review) {
                $codes = SubPrepBreakdown::forEntities([$entityId])->codesOf($entityId);
                $mixRowIds = $codes === [] ? [] : SAPMasterfile::whereIn('ItemCode', $codes)->pluck('id')->all();

                if ($mixRowIds === []) {
                    return;
                }

                $counts = MonthEndCountItem::whereIn('sap_masterfile_id', $mixRowIds)->where('status', 'level2_approved')
                    ->with('schedule')->get()->filter(fn ($line) => $line->schedule !== null)->groupBy('branch_id');

                foreach ($counts as $branchId => $mixLines) {
                    $schedule = $mixLines->sortByDesc(fn ($line) => $line->schedule->calculated_date?->toDateString().'|'.$line->schedule->id)->first()->schedule;
                    $branch = StoreBranch::find($branchId);
                    $ref = SubPrepCountStock::MARKER."{$schedule->id},{$branchId}";

                    if (! $branch || ProductInventoryStockManager::where('store_branch_id', $branchId)->where('remarks', 'like', '%'.$ref.'%')->exists()) {
                        continue;
                    }

                    $lines = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)->where('branch_id', $branchId)
                        ->where('status', 'level2_approved')->with('sapMasterfile')->get();

                    try {
                        $plan = $subPrepStock->plan($branch, $lines);
                    } catch (\RuntimeException $e) {
                        $review[] = [$branch->name, $schedule->id, $e->getMessage()];

                        continue;
                    }

                    // The mix rows must still hold exactly what the count put there.
                    $moved = collect($plan['mixes'])->first(fn ($mix) => abs((float) $stock->balance((int) $mix['row']->id, (int) $branchId) - $mix['stock_qty']) > 0.0001);
                    if ($moved) {
                        $review[] = [$branch->name, $schedule->id, "{$moved['item_code']} has moved since the count (stock ".$stock->balance((int) $moved['row']->id, (int) $branchId).", counted {$moved['stock_qty']})"];

                        continue;
                    }

                    // Dated with the count's approval, which put the stock on the mix.
                    $date = ProductInventoryStockManager::whereIn('product_inventory_id', array_map(fn ($mix) => (int) $mix['row']->id, $plan['mixes']))
                        ->where('store_branch_id', $branchId)->where('remarks', 'like', '%||MEC_REF::'.$schedule->id.','.$branchId)
                        ->max('transaction_date');
                    $date = $date ? substr((string) $date, 0, 10) : now('Asia/Manila')->toDateString();

                    foreach ($plan['inside'] as $part) {
                        $planned[] = [$branch->name, $schedule->id, $date, $part['item_code'], '+'.round($part['quantity'], 4).' '.$part['row']->AltUOM.' (row '.$part['row']->id.')', implode(', ', array_keys($part['sub_preps']))];
                    }
                    foreach ($plan['mixes'] as $mix) {
                        $planned[] = [$branch->name, $schedule->id, $date, $mix['item_code'], round($mix['stock_qty'], 4).' '.$mix['row']->AltUOM.' -> 0 (row '.$mix['row']->id.')', 'mix'];
                    }

                    if ($apply) {
                        DB::transaction(function () use ($subPrepStock, $plan, $schedule, $branch, $date) {
                            StoreBranch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
                            $subPrepStock->post($plan, $schedule, $branch, $date);
                        });
                    }
                }
            });
        }

        if ($planned) {
            $this->table(['Store', 'Count', 'Date', 'Item', 'Movement', 'From'], $planned);
        } else {
            $this->info('Nothing to move.');
        }

        if ($review) {
            $this->warn('Left for review:');
            $this->table(['Store', 'Count', 'Why'], $review);
        }

        $this->line($apply ? 'Applied.' : 'Dry run: nothing written. Run again with --apply to post these movements.');

        return self::SUCCESS;
    }
}
