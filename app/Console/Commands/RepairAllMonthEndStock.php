<?php

namespace App\Console\Commands;

use App\Models\ProductInventoryStockManager;
use App\Services\MonthEndHistoryRepair;
use App\Support\EntityContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RepairAllMonthEndStock extends Command
{
    protected $signature = 'stock:repair-month-end-all
        {--apply : Commit eligible item sequences; otherwise preview only}
        {--expect-database= : Required for apply; must exactly match the connected database}
        {--entity= : Optional entity ID}
        {--branch= : Optional branch ID}';
    protected $description = 'Audit or repair historical MEC sequences across stores, preserving later counts and original records';

    public function handle(MonthEndHistoryRepair $repair): int
    {
        $database = DB::connection()->getDatabaseName();
        if (($this->option('apply') || $this->option('expect-database')) && $this->option('expect-database') !== $database) {
            $this->error("Connected database is {$database}. Supply --expect-database with that exact name.");
            return self::FAILURE;
        }
        foreach (['entity','branch'] as $filter) {
            if ($this->option($filter) !== null && (!ctype_digit((string)$this->option($filter)) || (int)$this->option($filter) < 1)) {
                $this->error("--{$filter} must be a positive ID."); return self::FAILURE;
            }
        }
        $run = 'stock-repairs/bulk-'.now()->format('Ymd-His').'-'.Str::uuid();
        $reportPath = $run.'/report.jsonl';
        if (!Storage::put($reportPath,json_encode(['database'=>$database,'mode'=>$this->option('apply') ? 'apply':'preview','started_at'=>now()->toIso8601String()])."\n")) {
            $this->error('Cannot create repair report.'); return self::FAILURE;
        }
        // Stream locally: Storage::append rewrites the entire report on each item.
        $report = fopen(Storage::path($reportPath), 'ab');
        if (!$report) { $this->error('Repair reports require a writable local storage disk.'); return self::FAILURE; }
        $csvPath = $run.'/summary.csv';
        if (!Storage::put($csvPath,"\xEF\xBB\xBF")) { fclose($report); $this->error('Cannot create CSV summary.'); return self::FAILURE; }
        $csv = fopen(Storage::path($csvPath),'ab');
        if (!$csv) { fclose($report); $this->error('Cannot open CSV summary.'); return self::FAILURE; }
        fputcsv($csv,['Entity','Branch','Product ID','Item Code','Status','SOH Before','SOH After','Corrections','Review Reason']);
        // Include approved counts with no movement so gaps are reported rather than silently missed.
        $counts = DB::table('month_end_count_items as c')->leftJoin('sap_masterfiles as p', fn ($j) => $j->on('p.id','=','c.sap_masterfile_id')->on('p.entity_id','=','c.entity_id'))
            ->leftJoin('sap_masterfiles as b', fn ($j) => $j->on('b.ItemCode','=','p.ItemCode')->on('b.entity_id','=','c.entity_id')->on('b.BaseUOM','=','b.AltUOM'))
            ->where('c.status','level2_approved')->select('c.entity_id','c.branch_id','b.id as product_id','c.sap_masterfile_id as source_product_id','p.ItemCode as item_code')->distinct();
        $movements = ProductInventoryStockManager::withoutEntityScope()->where(fn ($q) => $q->where('remarks','like','%MEC_REF::%')->orWhere('remarks','like','%Month End Count%'))
            ->select('entity_id','store_branch_id as branch_id','product_inventory_id as product_id')->distinct();
        if ($this->option('entity')) { $counts->where('c.entity_id',$this->option('entity')); $movements->where('entity_id',$this->option('entity')); }
        if ($this->option('branch')) { $counts->where('c.branch_id',$this->option('branch')); $movements->where('store_branch_id',$this->option('branch')); }
        $targets = $counts->get()->concat($movements->get())->unique(fn ($r) => "$r->entity_id/$r->branch_id/".($r->product_id ?? 'missing-'.($r->source_product_id ?? 'unknown')))
            ->sortBy([['entity_id','asc'],['branch_id','asc'],['product_id','asc']])->values();
        $totals = ['applied'=>0,'would_repair'=>0,'unchanged'=>0,'review'=>0];
        $this->info("Database: {$database}; {$targets->count()} item/store sequences; ".($this->option('apply') ? 'APPLY' : 'PREVIEW'));
        foreach ($targets as $index => $target) {
            try {
                if (!$target->entity_id || !$target->branch_id || !$target->product_id) throw new \RuntimeException('Approved count lacks a valid entity, branch or base stock mapping.');
                $result = app(EntityContext::class)->runAs((int)$target->entity_id, fn () => $repair->run(
                    (int)$target->branch_id,(int)$target->product_id,(bool)$this->option('apply'),$run.'/'.($index+1).'.json'));
            } catch (\Throwable $e) {
                // Query/infrastructure errors stop the run; bad data is a reported item-level skip.
                if ($e instanceof \Illuminate\Database\QueryException) { $this->error($e->getMessage()); $this->line('Partial report: '.Storage::path($reportPath)); return self::FAILURE; }
                $result = ['entity_id'=>$target->entity_id,'branch_id'=>$target->branch_id,'product_id'=>$target->product_id,
                    'source_product_id'=>$target->source_product_id ?? null,'item_code'=>$target->item_code ?? null,'status'=>'review','reason'=>$e->getMessage()];
            }
            $totals[$result['status']]++;
            if (fwrite($report,json_encode($result,JSON_THROW_ON_ERROR)."\n") === false || !fflush($report)) {
                $this->error('Report write failed; stopping. Already committed item sequences remain recorded in the ledger.'); return self::FAILURE;
            }
            $cells = [$result['entity_id'],$result['branch_id'],$result['product_id'],$result['item_code'] ?? '',$result['status'],
                $result['before'] ?? '',$result['after'] ?? '',count($result['changes'] ?? []),$result['reason'] ?? ''];
            $cells = array_map(fn ($v) => is_string($v) && !is_numeric($v) && preg_match('/^[=+@\-\t\r\n]/',$v) ? "'".$v : $v,$cells);
            if (fputcsv($csv,$cells) === false || !fflush($csv)) { $this->error('CSV write failed; stopping. Consult JSONL and ledger for committed items.'); return self::FAILURE; }
            if (($index+1) % 100 === 0) $this->line(($index+1).'/'.$targets->count().' checked: '.json_encode($totals));
        }
        fclose($report);
        fclose($csv);
        $this->info(json_encode($totals));
        $this->line('Report: '.Storage::path($reportPath));
        $this->line('CSV summary: '.Storage::path($csvPath));
        $this->line($this->option('apply') ? 'Eligible repairs committed. Review entries were left unchanged; rerunning does not duplicate adjustments.' : 'Preview finished. No stock data changed.');
        return $totals['review'] ? 2 : self::SUCCESS;
    }
}
