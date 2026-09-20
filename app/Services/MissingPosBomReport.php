<?php

namespace App\Services;

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Illuminate\Support\Facades\DB;

/** Read-only sales evidence; independent of the first validation error on a receipt. */
class MissingPosBomReport
{
    public function build(array $branchIds, string $from, string $to, string $search = ''): array
    {
        $entity = app(EntityContext::class)->id();
        abort_unless($entity, 403);
        $branches = StoreBranch::whereIn('id', $branchIds)->get()->keyBy('id');
        $masters = POSMasterfile::get()->groupBy(fn ($p) => strtoupper(trim($p->POSCode)));
        $recipes = POSMasterfileBOM::pluck('POSCode')->map(fn ($c) => strtoupper(trim($c)))->flip();
        $exempt = array_map(fn ($c) => strtoupper(trim($c)), config("sales_posting.non_inventory_products.{$entity}", []));
        $rows = []; $warnings = []; $conflicts = 0;
        $add = function ($code, $branchId, $date, $identity, $source, $sourceName = '') use (&$rows, $masters, $recipes, $exempt, $branches) {
            $code = strtoupper(trim($code));
            if ($code === '' || in_array($code, $exempt, true) || !$branches->has($branchId)) return;
            $matches = $masters->get($code, collect());
            if ($matches->count() === 1 && $recipes->has($code)) return;
            $description = $matches->first()?->POSDescription ?: $sourceName;
            $key = $code.'|'.$branchId;
            $rows[$key] ??= ['code' => $code, 'description' => $description,
                'branch' => $branches[$branchId]->branch_code.' - '.$branches[$branchId]->name,
                'issue' => $matches->isEmpty() ? 'Missing POS masterfile' : ($matches->count() > 1 ? 'Ambiguous POS masterfile' : 'Missing BOM'),
                'first_sale' => $date, 'last_sale' => $date, 'source_receipts' => [], 'imported_receipts' => []];
            $rows[$key]['first_sale'] = min($rows[$key]['first_sale'], $date);
            $rows[$key]['last_sale'] = max($rows[$key]['last_sale'], $date);
            if ($rows[$key]['description'] === '') $rows[$key]['description'] = $description;
            $rows[$key][$source][$identity] = true;
        };
        $history = DB::table('store_transaction_items as i')->join('store_transactions as t', 't.id', '=', 'i.store_transaction_id')
            ->join('pos_masterfiles as p', 'p.id', '=', 'i.product_id')
            ->where('i.entity_id', $entity)->where('t.entity_id', $entity)->where('p.entity_id', $entity)
            ->whereIn('t.store_branch_id', $branches->keys())->whereBetween('t.order_date', [$from, $to])
            ->select('p.POSCode', 't.store_branch_id', 't.order_date', 't.id')->distinct()->get();
        foreach ($history as $r) $add($r->POSCode, $r->store_branch_id, substr($r->order_date, 0, 10), $r->id, 'imported_receipts');

        $profiles = collect(config('pos_sync.profiles', []))->filter(fn ($p) => (int) $p['entity_id'] === (int) $entity && $branches->has($p['branch_id']));
        $unmapped = $branches->keys()->diff($profiles->pluck('branch_id'));
        if ($unmapped->isNotEmpty()) $warnings[] = 'Some selected stores have no POS source mapping; only imported history is checked for those stores.';
        $db = DB::connection(config('pos_sync.connection'));
        $mapper = new PosReceiptMapper;
        foreach ($profiles as $profile) {
            $names = $db->table('mst_product')->where('fcompanyid', $profile['company'])
                ->orderByDesc('fupdated_date')->orderByDesc('_sync_timestamp')->get(['fproductid', 'fname'])
                ->groupBy(fn ($r) => strtoupper(trim($r->fproductid)))->map(fn ($copies) => trim($copies->first()->fname));
            // Report dates intentionally include history before the automation cutover.
            $headers = $db->table('pos_sale')->where('fcompanyid', $profile['company'])->where('fsiteid', $profile['site'])
                ->whereBetween('fsale_date', [str_replace('-', '', $from), str_replace('-', '', $to)])->get()
                ->groupBy(fn ($r) => json_encode([$r->fpubid, $r->frecno]));
            foreach ($headers->chunk(100) as $chunk) {
                $lineQuery = $db->table('pos_sale_product')->where('fcompanyid', $profile['company'])
                    ->where(function ($q) use ($chunk) {
                        foreach ($chunk as $copies) {
                            $h = $copies->first();
                            $q->orWhere(fn ($q) => $q->where('fpubid', $h->fpubid)->where('frecno', $h->frecno));
                        }
                    });
                $lines = $lineQuery->get()->groupBy(fn ($r) => json_encode([$r->fpubid, $r->frecno]));
                foreach ($chunk as $key => $copies) {
                    try {
                        $h = $mapper->uniqueRows($copies->map(fn ($r) => (array) $r)->all(), 'frecno')[0];
                        if ($h['fpost_flag'] !== '1' || $h['fvoid_flag'] !== '0' || $h['freturn_flag'] !== '0' || trim($h['ftrx_no']) === '0') continue;
                        $current = $mapper->uniqueRows($lines->get($key, collect())->map(fn ($r) => (array) $r)->all(), 'fseqno');
                    } catch (\InvalidArgumentException $e) { $conflicts++; continue; }
                    $date = substr($h['fsale_date'], 0, 4).'-'.substr($h['fsale_date'], 4, 2).'-'.substr($h['fsale_date'], 6, 2);
                    $identity = json_encode([$profile['company'], $profile['site'], $date, $h['ftermid'], $h['ftrx_no']]);
                    foreach ($current as $line) {
                        if ($line['fstatus_flag'] === '1' && $line['fsiteid'] === $profile['site'])
                            $add($line['fproductid'], $profile['branch_id'], $date, $identity, 'source_receipts', $names->get(strtoupper(trim($line['fproductid'])), ''));
                    }
                }
            }
        }
        if ($conflicts) $warnings[] = "{$conflicts} source receipts have conflicting versions and could not be assessed.";
        $result = collect($rows)->filter(fn ($r) => $search === '' || stripos($r['code'].' '.$r['description'], $search) !== false)->map(function ($r) {
            $r['source_receipts'] = count($r['source_receipts']);
            $r['imported_receipts'] = count($r['imported_receipts']);
            return $r;
        })->sortBy(['code', 'branch'])->values();
        $distinct = $result->reject(fn ($r) => $recipes->has($r['code']))->groupBy('code')->map(function ($group, $code) {
            return ['code' => $code, 'description' => $group->pluck('description')->filter()->first() ?? '',
                'stores' => $group->pluck('branch')->unique()->sort()->implode('; '), 'store_count' => $group->pluck('branch')->unique()->count(),
                'issue' => $group->first()['issue'], 'first_sale' => $group->min('first_sale'), 'last_sale' => $group->max('last_sale'),
                'source_receipts' => $group->sum('source_receipts'), 'imported_receipts' => $group->sum('imported_receipts')];
        })->values()->all();
        return ['rows' => $result->all(), 'distinct_rows' => $distinct, 'codes' => $result->pluck('code')->unique()->count(),
            'warnings' => $warnings, 'generated_at' => now()->toIso8601String()];
    }
}
