<?php

namespace App\Http\Services;

use App\Models\MonthEndSchedule;
use App\Models\Supplier;
use App\Models\SupplierItems;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Inventory Movement Report's per-item quantities, Theoretical SOH included.
 *
 * The Month End Count template's Current SOH is the same Theoretical SOH, so both
 * read it from here and can never drift apart.
 */
class InventoryMovementService
{
    /** SAP Item Types whose stock is used up in operations rather than sold through a recipe. */
    public const SUPPLIES_ITEM_TYPES = ['OPERATING SUPPLIES', 'CLEANING SUPPLIES'];

    /** The Supplier Items category that tags an item as supplies. */
    public const SUPPLIES_CATEGORY = 'SUPPLIES';

    /** Above this many item codes the list is left out of the queries - see movementDataForBranches(). */
    private const ITEM_FILTER_LIMIT = 200;

    /**
     * One row per ItemCode, every quantity converted into the item's SAP BaseUOM (36 Gm sold = 0.036 Bag).
     *
     * An item carries several sap_masterfiles rows (one per AltUOM, and since SAP restates
     * packs in a second base, possibly two BaseUOM=AltUOM rows). Orders are in the ordered
     * unit, sales in the BOM unit, wastage in the chosen row's AltUOM and counts in their own
     * uom, so each source is summed per (ItemCode, unit) and converted here. Joining back to
     * sap_masterfiles by ItemCode would count every line once per matching row.
     */
    public function movementData($sapItems, array $filters): array
    {
        if (empty($filters['branch_id'])) {
            return [];
        }

        return $this->movementDataForBranches($sapItems, [$filters['branch_id']], $filters)[(int) $filters['branch_id']] ?? [];
    }

    /**
     * movementData() for several branches in one pass. The Qty Variance / Cost Variance
     * report reads a whole count across stores from here, so its figures are the report's
     * own and one set of queries serves every store.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array<int, array<string, mixed>>> branch id => rows, as movementData() returns them
     */
    public function movementDataForBranches($sapItems, array $branchIds, array $filters): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        $movementData = array_fill_keys($branchIds, []);

        if (empty($branchIds) || empty($sapItems)) {
            return $movementData;
        }

        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];
        $itemsByCode = collect($sapItems)->filter(fn ($sapItem) => filled($sapItem->ItemCode))->groupBy('ItemCode');
        // As strings: a numeric code ("213") turns into an integer array key, and SQL Server
        // then casts the whole ItemCode column to int and fails on the first lettered code.
        $sapItemCodes = $itemsByCode->keys()->map(fn ($itemCode) => (string) $itemCode)->all();
        // A long item list is not sent to SQL Server: binding it costs about half a
        // millisecond per code in every query of every request (a month's items took seconds),
        // and it can exceed the 2100 parameter limit. Each query is already narrowed to the
        // branches and the period, and rows of other items are never looked up below.
        $itemFilter = count($sapItemCodes) > self::ITEM_FILTER_LIMIT ? null : $sapItemCodes;
        $onlyItems = fn (string $column) => fn ($query) => $itemFilter === null ? $query : $query->whereIn($column, $itemFilter);
        $selectedSupplier = !empty($filters['supplier_code']) && $filters['supplier_code'] !== 'all'
            ? Supplier::where('supplier_code', $filters['supplier_code'])->first()
            : null;

        $supplierItemQuery = SupplierItems::with('supplier')
            ->where('is_active', true)
            ->tap($onlyItems('ItemCode'));

        if (!empty($filters['supplier_code']) && $filters['supplier_code'] !== 'all') {
            $supplierItemQuery->where('SupplierCode', $filters['supplier_code']);
        }

        $supplierItems = $supplierItemQuery->get(['ItemCode', 'uom', 'SupplierCode']);

        $supplierLookup = $supplierItems
            ->groupBy('ItemCode')
            ->map(fn ($items) => $items
                ->map(fn ($supplierItem) => $supplierItem->supplier?->name ?? $supplierItem->SupplierCode)
                ->filter()
                ->unique()
                ->sort()
                ->implode(', ')
            );

        $suppliesTags = $this->suppliesTags($sapItems, $onlyItems);

        $metrics = ['ordered', 'committed', 'received', 'sales', 'wastage', 'interco_in', 'interco_out', 'beg_bal', 'actual_mec'];
        $totals = array_fill_keys($metrics, collect());
        // The wastage rows that came from a wasted Sub-Prep, kept apart so the report can name it.
        $subPrepWastage = collect();

        $prevMonth = Carbon::parse($dateFrom)->subMonth();
        $begMecSchedule = MonthEndSchedule::where('year', $prevMonth->year)
            ->where('month', $prevMonth->month)
            ->first();

        $currMonth = Carbon::parse($dateTo);
        $currMecSchedule = MonthEndSchedule::where('year', $currMonth->year)
            ->where('month', $currMonth->month)
            ->first();

        $unitKey = fn (string $unitColumn) => "UPPER(LTRIM(RTRIM(COALESCE({$unitColumn}, ''))))";
        $unitSum = fn (string $branchColumn, string $itemColumn, string $unitColumn, string $qtyExpression) => [
            "{$branchColumn} as branch_id",
            "{$itemColumn} as item_code",
            DB::raw($unitKey($unitColumn) . ' as unit'),
            DB::raw("SUM({$qtyExpression}) as qty"),
        ];

        // SQL Server limitation: max 2100 parameters. Chunking branches into 1000 to be safe.
        foreach (array_chunk($branchIds, 1000) as $branchChunk) {
            $procurement = fn (string $branchColumn = 'so.store_branch_id') => DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->tap($onlyItems('soi.item_code'))
                ->whereIn($branchColumn, $branchChunk)
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->groupBy($branchColumn, 'soi.item_code', DB::raw($unitKey('soi.uom')));

            // 1. Ordered (Regular Procurement)
            $totals['ordered'] = $totals['ordered']->merge($procurement()
                ->whereNull('so.interco_number')
                ->select($unitSum('so.store_branch_id', 'soi.item_code', 'soi.uom', 'COALESCE(soi.quantity_approved, 0)'))
                ->get());

            // 1.5 Committed (Regular Procurement)
            $totals['committed'] = $totals['committed']->merge($procurement()
                ->whereNull('so.interco_number')
                // Auto-committed orders can have no committer. Use the same
                // eligible statuses as receiving, while retaining explicit line commitments.
                ->where(function ($query) {
                    $query->whereNotNull('soi.committed_by')
                        ->orWhereIn('so.order_status', \App\Http\Services\OrderReceivingService::RECEIVING_STATUSES);
                })
                ->select($unitSum('so.store_branch_id', 'soi.item_code', 'soi.uom', 'COALESCE(soi.quantity_commited, 0)'))
                ->get());

            // 1.6 Received (Regular Procurement) and 4. Interco Inbound (received by this store)
            foreach (['received' => 'whereNull', 'interco_in' => 'whereNotNull'] as $metric => $intercoClause) {
                $totals[$metric] = $totals[$metric]->merge($procurement()
                    ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
                    ->{$intercoClause}('so.interco_number')
                    ->where('oird.status', 'approved')
                    ->select($unitSum('so.store_branch_id', 'soi.item_code', 'soi.uom', 'COALESCE(oird.quantity_received, 0)'))
                    ->get());
            }

            // 5. Interco Outbound (Shipped from this store)
            $totals['interco_out'] = $totals['interco_out']->merge($procurement('so.sending_store_branch_id')
                ->whereNotNull('so.interco_number')
                ->select($unitSum('so.sending_store_branch_id', 'soi.item_code', 'soi.uom', 'COALESCE(soi.quantity_commited, 0)'))
                ->get());

            // 2. Sales (dated by the POS sales date, not the import timestamp). The BOM is
            // matched within the product's entity, as sales posting does.
            $totals['sales'] = $totals['sales']->merge(DB::table('store_transaction_items as sti')
                ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
                ->join('pos_masterfiles as pm', 'sti.product_id', '=', 'pm.id')
                ->join('pos_masterfiles_bom as bom', function ($join) {
                    $join->on('pm.POSCode', '=', 'bom.POSCode')
                        ->where(function ($entity) {
                            $entity->whereColumn('pm.entity_id', 'bom.entity_id')
                                ->orWhere(fn ($legacy) => $legacy->whereNull('pm.entity_id')->whereNull('bom.entity_id'));
                        });
                })
                ->whereIn('st.store_branch_id', $branchChunk)
                ->whereBetween('st.order_date', [$dateFrom, $dateTo])
                ->tap($onlyItems('bom.ItemCode'))
                ->select($unitSum('st.store_branch_id', 'bom.ItemCode', 'bom.BOMUOM', 'COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0)'))
                ->groupBy('st.store_branch_id', 'bom.ItemCode', DB::raw($unitKey('bom.BOMUOM')))
                ->get());

            // 3. Wastage, in the unit of the masterfile row it was filed against
            $totals['wastage'] = $totals['wastage']->merge(DB::table('wastages')
                ->join('sap_masterfiles as sap', 'wastages.sap_masterfile_id', '=', 'sap.id')
                ->whereIn('wastages.store_branch_id', $branchChunk)
                ->where('wastages.wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                ->tap($onlyItems('sap.ItemCode'))
                ->whereBetween('wastages.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->select($unitSum('wastages.store_branch_id', 'sap.ItemCode', 'sap.AltUOM', 'COALESCE(wastages.approverlvl2_qty, 0)'))
                ->groupBy('wastages.store_branch_id', 'sap.ItemCode', DB::raw($unitKey('sap.AltUOM')))
                ->get());

            // 3.5 A wasted Sub-Prep is a POS item with no stock of its own: it is charged to
            // the raw materials on its BOM, the BOM Qty of each per unit wasted, as a sale is.
            // What was wasted is summed per Sub-Prep first, so each row can say which one it is.
            $subPrepWasted = DB::table('wastages')
                ->whereNull('sap_masterfile_id')
                ->whereNotNull('pos_masterfile_id')
                ->whereIn('store_branch_id', $branchChunk)
                ->where('wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->groupBy('store_branch_id', 'pos_masterfile_id')
                ->select('store_branch_id', 'pos_masterfile_id', DB::raw('SUM(COALESCE(approverlvl2_qty, 0)) as wasted'));

            $subPrepRows = DB::query()->fromSub($subPrepWasted, 'w')
                ->join('pos_masterfiles as pm', 'w.pos_masterfile_id', '=', 'pm.id')
                ->join('pos_masterfiles_bom as bom', function ($join) {
                    $join->on('pm.POSCode', '=', 'bom.POSCode')
                        ->where(function ($entity) {
                            $entity->whereColumn('pm.entity_id', 'bom.entity_id')
                                ->orWhere(fn ($legacy) => $legacy->whereNull('pm.entity_id')->whereNull('bom.entity_id'));
                        });
                })
                ->tap($onlyItems('bom.ItemCode'))
                ->select(array_merge(
                    $unitSum('w.store_branch_id', 'bom.ItemCode', 'bom.BOMUOM', 'w.wasted * COALESCE(bom.BOMQty, 0)'),
                    ['pm.POSCode as pos_code', 'pm.POSDescription as pos_description', 'pm.UOM as pos_uom', 'w.wasted']
                ))
                ->groupBy('w.store_branch_id', 'bom.ItemCode', DB::raw($unitKey('bom.BOMUOM')), 'pm.POSCode', 'pm.POSDescription', 'pm.UOM', 'w.wasted')
                ->get();

            $totals['wastage'] = $totals['wastage']->merge($subPrepRows);
            $subPrepWastage = $subPrepWastage->merge($subPrepRows);

            // 6. MEC Beginning Balance and 7. Actual MEC, in the count's own uom
            foreach (['beg_bal' => $begMecSchedule, 'actual_mec' => $currMecSchedule] as $metric => $schedule) {
                if (!$schedule) {
                    continue;
                }

                $countUnit = "NULLIF(meci.uom, ''), sap.AltUOM";
                $totals[$metric] = $totals[$metric]->merge(DB::table('month_end_count_items as meci')
                    ->join('sap_masterfiles as sap', 'meci.sap_masterfile_id', '=', 'sap.id')
                    ->where('meci.month_end_schedule_id', $schedule->id)
                    ->whereIn('meci.branch_id', $branchChunk)
                    // A rejected count was sent back to the store; it is not a count.
                    ->where('meci.status', '!=', 'rejected')
                    ->tap($onlyItems('sap.ItemCode'))
                    ->select($unitSum('meci.branch_id', 'sap.ItemCode', $countUnit, 'COALESCE(meci.total_qty, 0)'))
                    ->groupBy('meci.branch_id', 'sap.ItemCode', DB::raw($unitKey($countUnit)))
                    ->get());
            }
        }

        $totals = array_map(fn ($rows) => $rows->groupBy(['branch_id', 'item_code']), $totals);
        $subPrepWastage = $subPrepWastage->groupBy(['branch_id', 'item_code']);

        // The units belong to the item, whichever branch holds it.
        $units = $itemsByCode->map(function ($rows) {
            [$displayUnit, $unitSizes] = $this->resolveUnits($rows);
            // A line with no unit is taken to be in the stock unit, when the item has only one.
            $stockUnits = $rows->filter(fn ($row) => strcasecmp(trim((string) $row->AltUOM), trim((string) $row->BaseUOM)) === 0)
                ->map(fn ($row) => strtoupper(trim((string) $row->BaseUOM)))
                ->unique();

            return [$displayUnit, $unitSizes, $stockUnits->count() === 1 ? $stockUnits->first() : ''];
        });

        foreach ($branchIds as $branchId) {
            $branchTotals = array_map(fn ($byBranch) => $byBranch->get($branchId, collect()), $totals);

            foreach ($itemsByCode as $itemCode => $rows) {
                [$displayUnit, $unitSizes, $blankUnit] = $units[$itemCode];
                $unconverted = [];
                $values = [];
                $procurementSources = [];

                foreach ($metrics as $metric) {
                    $values[$metric] = 0.0;

                    foreach ($branchTotals[$metric]->get($itemCode, []) as $row) {
                        $size = $unitSizes[$row->unit === '' ? $blankUnit : $row->unit] ?? null;

                        if ($size === null) {
                            $unconverted[] = $row->unit === '' ? '(blank)' : $row->unit;
                            continue;
                        }

                        $values[$metric] += (float) $row->qty * $size;

                        if (in_array($metric, ['ordered', 'committed', 'received'], true)) {
                            $sourceKey = $row->unit === '' ? $blankUnit : $row->unit;
                            $sourceLabel = $rows->flatMap(fn ($sapRow) => [$sapRow->AltUOM, $sapRow->BaseUOM])
                                ->first(fn ($label) => strtoupper(trim((string) $label)) === $sourceKey) ?? $sourceKey;
                            $procurementSources[$metric][] = [
                                'quantity' => (float) $row->qty,
                                'uom' => trim((string) $sourceLabel),
                                'conversion_factor' => $size,
                            ];
                        }
                    }

                    $values[$metric] = round($values[$metric], 6);
                }

                // The part of the wastage that came from wasted Sub-Preps, per Sub-Prep, in the
                // display unit. A unit that does not convert is already under unconverted_units.
                $subPreps = [];
                foreach ($subPrepWastage->get($branchId, collect())->get($itemCode, []) as $row) {
                    $size = $unitSizes[$row->unit === '' ? $blankUnit : $row->unit] ?? null;

                    if ($size === null) {
                        continue;
                    }

                    $subPreps[$row->pos_code] ??= [
                        'code' => $row->pos_code,
                        'description' => $row->pos_description,
                        'uom' => trim((string) $row->pos_uom),
                        'wasted_qty' => (float) $row->wasted,
                        'quantity' => 0.0,
                    ];
                    $subPreps[$row->pos_code]['quantity'] = round($subPreps[$row->pos_code]['quantity'] + (float) $row->qty * $size, 6);
                }

                $theoretical = $values['beg_bal'] + $values['received'] + $values['interco_in']
                    - $values['sales'] - $values['wastage'] - $values['interco_out'];

                // Supplies are used up without a transaction (gloves, cleaners, tissue), so only
                // the month end count shows how much went: whatever the other movements leave
                // unexplained. Recipe items (cups, lids) are already in Sales, so only the rest
                // counts. A count above the books is a gain, not usage, and stays as a variance.
                $suppliesType = $suppliesTags[$itemCode] ?? null;
                $suppliesCounted = $suppliesType !== null && $branchTotals['actual_mec']->has($itemCode);
                $supplies = $suppliesCounted ? max(0.0, round($theoretical - $values['actual_mec'], 6)) : 0.0;
                $theoretical -= $supplies;

                $movementData[$branchId][] = [
                    'supplier' => ($filters['supplier_code'] ?? null) === 'CPO'
                        ? ($selectedSupplier?->name ?? 'CPO')
                        : $supplierLookup->get($itemCode, ''),
                    'sap_code' => $itemCode,
                    'item_description' => $rows->first()->ItemDescription,
                    'uom' => $displayUnit,
                    'ordered_qty' => $values['ordered'],
                    'committed_qty' => $values['committed'],
                    'received_qty' => $values['received'],
                    'beg_bal_qty' => $values['beg_bal'],
                    'sales_qty' => $values['sales'],
                    'wastage_qty' => $values['wastage'],
                    // Which wasted Sub-Preps are in that figure, and how much of it each is.
                    'wastage_sub_preps' => array_values($subPreps),
                    'supplies_qty' => $supplies,
                    'supplies_type' => $suppliesType,
                    'supplies_counted' => $suppliesCounted,
                    'interco_in_qty' => $values['interco_in'],
                    'interco_out_qty' => $values['interco_out'],
                    'theoretical_qty' => round($theoretical, 6),
                    'actual_mec' => $values['actual_mec'],
                    // Actual MEC - Theoretical SOH. The + 0.0 turns a rounded -0.0 into 0.
                    'variance_qty' => round($values['actual_mec'] - $theoretical, 6) + 0.0,
                    'procurement_sources' => $procurementSources,
                    // Quantities in a unit with no conversion to the display unit are left out.
                    'unconverted_units' => array_values(array_unique($unconverted)),
                ];
            }
        }

        return $movementData;
    }

    /**
     * Supplies items and the tag that makes them so: an OPERATING / CLEANING SUPPLIES
     * SAP Item Type, else a "Supplies" Supplier Items category.
     *
     * @param  \Closure  $onlyItems  column => the query narrowing to the report's items, as movementDataForBranches() builds it
     * @return array<string, string> ItemCode => tag label
     */
    private function suppliesTags($sapItems, \Closure $onlyItems): array
    {
        $entityIds = collect($sapItems)->pluck('entity_id')->filter()->unique()->values()->all();
        $tags = [];

        $byCategory = SupplierItems::query()
            ->tap($onlyItems('ItemCode'))
            ->whereRaw('UPPER(LTRIM(RTRIM(category))) = ?', [self::SUPPLIES_CATEGORY])
            ->distinct()
            ->pluck('ItemCode');

        foreach ($byCategory as $itemCode) {
            $tags[$itemCode] = 'Supplies';
        }

        // The Item Type belongs to the ItemCode per entity (sap_item_type_assignments).
        $byType = DB::table('sap_item_type_assignments as sita')
            ->join('sap_item_types as sit', 'sit.id', '=', 'sita.sap_item_type_id')
            ->tap($onlyItems('sita.item_code'))
            ->when($entityIds, fn ($q) => $q->whereIn('sita.entity_id', $entityIds))
            ->whereIn(DB::raw('UPPER(LTRIM(RTRIM(sit.name)))'), self::SUPPLIES_ITEM_TYPES)
            ->get(['sita.item_code', 'sit.name']);

        foreach ($byType as $row) {
            $tags[$row->item_code] = ucwords(strtolower(trim($row->name)));
        }

        return $tags;
    }

    /**
     * The unit the item's stock is kept in (its SAP base unit) and how many of it each
     * of its units holds - the same resolution every stock writer uses.
     *
     * @return array{0: string, 1: array<string, float>} display unit, and size per UPPER unit
     */
    private function resolveUnits($rows): array
    {
        $stockUnit = \App\Support\ItemStockUnit::fromRows(collect($rows));

        return [$stockUnit->unit(), $stockUnit->factors()];
    }
}
