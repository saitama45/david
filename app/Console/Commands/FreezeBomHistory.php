<?php

namespace App\Console\Commands;

use App\Enums\WastageStatus;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\Wastage;
use App\Support\BomConsumptions;
use App\Support\ItemStockUnit;
use App\Support\PosRecipeHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Freezes what past sales and Sub-Prep wastage used, so editing a BOM no longer moves their
 * figures in the Inventory Movement Report (and the reports that read it), and copies the
 * BOM / POS item changes the audit log already holds into the BOM change history.
 *
 * - A sale with a posting record is frozen at what that posting really deducted.
 * - An older sale, and an approved Sub-Prep wastage, at the BOM as it stands now (nothing
 *   older was kept); from then on it no longer follows the BOM.
 *
 * Dry run unless --apply; running it again freezes only what is still open.
 */
class FreezeBomHistory extends Command
{
    protected $signature = 'bom:freeze-history
        {--apply : Write the lines and the history; without it nothing is written}
        {--branch= : Only this store (store_branches.id)}
        {--output= : A CSV of the Sales figure per store, month, item and unit: today\'s BOM vs frozen}';

    protected $description = 'Freeze the ingredients past sales and Sub-Prep wastage used, and copy past BOM / POS item audits into the change history (dry run unless --apply)';

    private const SALES_PER_BATCH = 200;

    private const ROWS_PER_INSERT = 80;

    /** @var array<string, ItemStockUnit> */
    private array $stockUnits = [];

    /** @var array<string, Collection> */
    private array $boms = [];

    /** @var array<string, object|null> */
    private array $stockRows = [];

    /** @var array<string, array{0: float, 1: float}> store|month|item|unit => [today's BOM, frozen] */
    private array $comparison = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $branch = $this->option('branch') !== null ? (int) $this->option('branch') : null;

        $this->info($apply ? 'Freezing.' : 'Dry run: nothing is written. Run again with --apply to write.');

        $sales = $this->freezeSales($apply, $branch);
        $wastages = $this->freezeWastages($apply, $branch);
        $history = $this->copyAudits($apply);

        $changed = collect($this->comparison)->filter(fn ($pair) => abs($pair[0] - $pair[1]) > 0.000001)->count();

        $this->table(['What', 'Count'], [
            ['Sales frozen from their posting record', $sales['snapshot']],
            ['Sales frozen at today\'s BOM (no posting record)', $sales['current_bom']],
            ['Ingredient lines for those sales', $sales['lines']],
            ['Sub-Prep wastages frozen at today\'s BOM', $wastages['wastages']],
            ['Ingredient lines for those wastages', $wastages['lines']],
            ['Audit log changes copied into the BOM change history', $history],
            ['Store / month / item figures that move (today\'s BOM vs frozen)', $changed],
        ]);

        if ($this->option('output')) {
            $this->writeComparison((string) $this->option('output'));
        }

        return self::SUCCESS;
    }

    /** @return array{snapshot: int, current_bom: int, lines: int} */
    private function freezeSales(bool $apply, ?int $branch): array
    {
        $stats = ['snapshot' => 0, 'current_bom' => 0, 'lines' => 0];
        $open = DB::table('store_transactions')->whereNull('bom_frozen_at')
            ->when($branch !== null, fn ($query) => $query->where('store_branch_id', $branch));
        $total = (clone $open)->count();
        $done = 0;
        $started = microtime(true);
        $this->line("Sales to freeze: {$total}");

        // One transaction per 200 sales, their lines in multi-row inserts: against a distant
        // database each round trip costs, and one transaction per sale took ~3 s a sale.
        // An interrupted run loses at most the batch in progress; running again resumes.
        $open->select(['id', 'store_branch_id', 'order_date'])
            ->chunkById(self::SALES_PER_BATCH, function (Collection $sales) use ($apply, &$stats, &$done, $total, $started) {
                $rows = [];
                $ids = $sales->pluck('id')->all();
                $postings = DB::table('sales_postings')->whereIn('store_transaction_id', $ids)->get()->keyBy('store_transaction_id');
                $items = DB::table('store_transaction_items as sti')
                    ->join('pos_masterfiles as pm', 'sti.product_id', '=', 'pm.id')
                    ->whereIn('sti.store_transaction_id', $ids)
                    ->orderBy('sti.id')
                    ->get(['sti.id', 'sti.store_transaction_id', 'sti.product_id', 'sti.quantity', 'pm.POSCode', 'pm.POSDescription', 'pm.entity_id'])
                    ->groupBy('store_transaction_id');
                $entities = DB::table('store_branches')->whereIn('id', $sales->pluck('store_branch_id')->unique()->all())->pluck('entity_id', 'id');

                foreach ($sales as $sale) {
                    $entityId = $entities[$sale->store_branch_id] ?? null;
                    $entityId = $entityId !== null ? (int) $entityId : null;
                    $saleItems = $items->get($sale->id, collect());
                    $current = $this->currentBomLines($sale, $saleItems, $entityId);
                    $posting = $postings->get($sale->id);
                    $lines = $posting ? $this->snapshotLines($sale, $posting, $saleItems, $entityId) : $current;

                    $this->compare($sale->store_branch_id, (string) $sale->order_date, $current, $lines);
                    $stats[$posting ? 'snapshot' : 'current_bom']++;
                    $stats['lines'] += count($lines);

                    foreach ($lines as $line) {
                        $rows[] = BomConsumptions::row($line);
                    }
                }

                if ($apply) {
                    DB::transaction(function () use ($rows, $sales) {
                        // 23 columns a row: 80 rows stay under SQL Server's 2,100 parameters.
                        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
                            DB::table('bom_consumptions')->insert($chunk);
                        }
                        // Frozen even with no lines: it no longer reads the BOM at all.
                        DB::table('store_transactions')->whereIn('id', $sales->pluck('id')->all())->update(['bom_frozen_at' => now()]);
                    });
                }

                $done += $sales->count();
                $rate = $done / max(microtime(true) - $started, 0.001);
                $left = $rate > 0 ? (int) round(($total - $done) / $rate / 60) : 0;
                $this->line(sprintf('  sales %s of %s (%s lines)%s', number_format($done), number_format($total), number_format($stats['lines']),
                    $done < $total ? ", about {$left} min left" : ''));
            });

        return $stats;
    }

    /**
     * What the sale uses at today's BOM: every BOM line of each product, matched within the
     * product's entity - exactly what the report summed before the lines were frozen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function currentBomLines(object $sale, Collection $items, ?int $entityId): array
    {
        $lines = [];

        foreach ($items as $item) {
            foreach ($this->bom($item->POSCode, $item->entity_id !== null ? (int) $item->entity_id : null) as $recipe) {
                $qty = (float) $recipe->BOMQty * (float) $item->quantity;
                $stockUnit = $this->stockUnit($recipe->ItemCode, $entityId);
                $factor = $stockUnit->factor($recipe->BOMUOM);

                $lines[] = $this->saleLine($sale, $entityId, $item, BomConsumptions::CURRENT_BOM) + [
                    'bom_line_id' => $recipe->id,
                    'item_code' => $recipe->ItemCode,
                    'bom_uom' => $recipe->BOMUOM,
                    'bom_qty' => $recipe->BOMQty,
                    'units' => $item->quantity,
                    'qty' => $qty,
                    'stock_row_id' => $stockUnit->stockRowFor($recipe->BOMUOM)?->id,
                    'stock_qty' => $factor ? $qty * $factor : null,
                    'unit_cost' => $factor ? (float) $recipe->UnitCost / $factor : null,
                ];
            }
        }

        return $lines;
    }

    /**
     * What the sale's posting really deducted, one line per stock movement it recorded. The
     * movement's remark names the POS code and BOM line ("COFFEE: BOM 0.5 KG"), so the quantity
     * is put back into that BOM unit; a remark that cannot be read keeps the stock row's unit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snapshotLines(object $sale, object $posting, Collection $items, ?int $entityId): array
    {
        $lines = [];

        foreach (json_decode($posting->movements, true, 512, JSON_THROW_ON_ERROR) as $movement) {
            $stockRow = $this->stockRow((int) $movement['product_inventory_id']);

            if ($stockRow === null) {
                continue;
            }

            $stockQty = (float) $movement['quantity'] * (($movement['action'] ?? 'out') === 'add' ? -1 : 1);
            $posCode = null;
            $bomQty = 0.0;
            $bomUom = trim((string) $stockRow->AltUOM);
            $qty = $stockQty;

            if (preg_match('/; ([^;]+?): BOM (\S+) ([^;]+)/', (string) ($movement['remarks'] ?? ''), $match)) {
                $posCode = trim($match[1]);
                $factor = $this->stockUnit($stockRow->ItemCode, $entityId)->factor(trim($match[3]));

                if ($factor && is_numeric($match[2]) && (float) $match[2] != 0) {
                    $bomQty = (float) $match[2];
                    $bomUom = trim($match[3]);
                    $qty = $stockQty / $factor;
                }
            }

            $item = $items->first(fn ($each) => $posCode !== null && strtoupper(trim((string) $each->POSCode)) === strtoupper($posCode));

            $lines[] = $this->saleLine($sale, $entityId, $item, BomConsumptions::SNAPSHOT) + [
                'pos_code' => $item->POSCode ?? $posCode,
                'item_code' => $stockRow->ItemCode,
                'bom_uom' => $bomUom,
                'bom_qty' => $bomQty,
                'units' => $bomQty != 0 ? $qty / $bomQty : 0,
                'qty' => $qty,
                'stock_row_id' => $stockRow->id,
                'stock_qty' => $stockQty,
                'unit_cost' => $movement['unit_cost'] ?? null,
                'movement_id' => $movement['id'] ?? null,
            ];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function saleLine(object $sale, ?int $entityId, ?object $item, string $origin): array
    {
        return [
            'entity_id' => $entityId,
            'store_branch_id' => $sale->store_branch_id,
            'business_date' => substr((string) $sale->order_date, 0, 10),
            'source_type' => BomConsumptions::SALE,
            'origin' => $origin,
            'store_transaction_id' => $sale->id,
            'store_transaction_item_id' => $item?->id,
            'pos_masterfile_id' => $item?->product_id,
            'pos_code' => $item?->POSCode,
            'pos_description' => $item?->POSDescription,
        ];
    }

    /** @return array{wastages: int, lines: int} */
    private function freezeWastages(bool $apply, ?int $branch): array
    {
        $stats = ['wastages' => 0, 'lines' => 0];

        Wastage::query()
            ->whereNull('sap_masterfile_id')
            ->whereNotNull('pos_masterfile_id')
            ->where('wastage_status', WastageStatus::APPROVED_LVL2->value)
            ->whereNull('bom_frozen_at')
            ->when($branch !== null, fn ($query) => $query->where('store_branch_id', $branch))
            ->with('posMasterfile')
            ->chunkById(200, function (Collection $wastages) use ($apply, &$stats) {
                foreach ($wastages as $wastage) {
                    $stats['wastages']++;

                    if ($apply) {
                        $stats['lines'] += DB::transaction(fn () => BomConsumptions::recordSubPrepWastage($wastage, BomConsumptions::CURRENT_BOM));
                    } elseif ($wastage->posMasterfile) {
                        $stats['lines'] += \App\Support\SubPrepRecipe::ingredients($wastage->posMasterfile)->count();
                    }
                }
            });

        return $stats;
    }

    /**
     * Copies the form changes the audit log recorded for BOM lines and POS items before the
     * change history existed. Only audits older than the first change the history recorded
     * itself, since every change since then is in both.
     */
    private function copyAudits(bool $apply): int
    {
        $subjects = [
            POSMasterfileBOM::class => [PosRecipeHistory::BOM_LINE, 'pos_masterfiles_bom'],
            POSMasterfile::class => [PosRecipeHistory::POS_ITEM, 'pos_masterfiles'],
        ];
        $since = DB::table('pos_recipe_changes')->where('source', '!=', PosRecipeHistory::AUDIT_LOG)->min('changed_at') ?? now();
        $copied = 0;

        DB::table('audits')
            ->whereIn('auditable_type', array_keys($subjects))
            ->whereIn('event', [PosRecipeHistory::CREATED, PosRecipeHistory::UPDATED, PosRecipeHistory::DELETED])
            ->where('created_at', '<', $since)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('pos_recipe_changes as prc')->whereColumn('prc.audit_id', 'audits.id'))
            ->chunkById(500, function (Collection $audits) use ($apply, $subjects, &$copied) {
                foreach ($audits as $audit) {
                    [$subject, $table] = $subjects[$audit->auditable_type];
                    $old = (array) (json_decode((string) $audit->old_values, true) ?: []);
                    $new = (array) (json_decode((string) $audit->new_values, true) ?: []);
                    // An update audit holds only the fields it changed; the row today supplies
                    // the POS code and item it belongs to.
                    $current = (array) (DB::table($table)->where('id', $audit->auditable_id)->first() ?? []);
                    $identity = ['id' => $audit->auditable_id];

                    [$before, $after] = match ($audit->event) {
                        PosRecipeHistory::CREATED => [null, $identity + $new + $current],
                        PosRecipeHistory::DELETED => [$identity + $old, null],
                        default => [$identity + array_merge($current, $old), $identity + array_merge($current, $new)],
                    };

                    if (! $apply) {
                        $copied++;

                        continue;
                    }

                    $args = [$audit->event, $before, $after, PosRecipeHistory::AUDIT_LOG, null,
                        (string) $audit->created_at, $audit->user_id !== null ? (int) $audit->user_id : null, (int) $audit->id];
                    $written = $subject === PosRecipeHistory::BOM_LINE
                        ? PosRecipeHistory::bomLine(...$args)
                        : PosRecipeHistory::posItem(...$args);
                    $copied += $written ? 1 : 0;
                }
            });

        return $copied;
    }

    /** @param  array<int, array<string, mixed>>  $current  @param  array<int, array<string, mixed>>  $frozen */
    private function compare(int|string $branchId, string $date, array $current, array $frozen): void
    {
        foreach ([0 => $current, 1 => $frozen] as $side => $lines) {
            foreach ($lines as $line) {
                $key = implode('|', [$branchId, substr($date, 0, 7), $line['item_code'], strtoupper(trim((string) $line['bom_uom']))]);
                $this->comparison[$key] ??= [0.0, 0.0];
                $this->comparison[$key][$side] += (float) $line['qty'];
            }
        }
    }

    private function writeComparison(string $path): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['store_branch_id', 'month', 'item_code', 'unit', 'sales_at_todays_bom', 'sales_frozen', 'difference']);

        foreach ($this->comparison as $key => [$current, $frozen]) {
            fputcsv($handle, array_merge(explode('|', $key), [round($current, 6), round($frozen, 6), round($frozen - $current, 6)]));
        }

        fclose($handle);
        $this->info("Before / after written to {$path}.");
    }

    private function bom(string $posCode, ?int $entityId): Collection
    {
        return $this->boms[strtoupper($posCode) . '|' . $entityId] ??= DB::table('pos_masterfiles_bom')
            ->where('POSCode', $posCode)
            ->when($entityId !== null, fn ($query) => $query->where('entity_id', $entityId), fn ($query) => $query->whereNull('entity_id'))
            ->orderBy('id')
            ->get(['id', 'ItemCode', 'BOMUOM', 'BOMQty', 'UnitCost']);
    }

    private function stockUnit(string $itemCode, ?int $entityId): ItemStockUnit
    {
        return $this->stockUnits[$itemCode . '|' . $entityId] ??= ItemStockUnit::forItem($itemCode, $entityId);
    }

    private function stockRow(int $id): ?object
    {
        return $this->stockRows[$id] ??= DB::table('sap_masterfiles')->where('id', $id)->first(['id', 'ItemCode', 'AltUOM', 'BaseUOM']);
    }
}
