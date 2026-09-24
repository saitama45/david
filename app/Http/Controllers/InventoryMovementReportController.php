<?php

namespace App\Http\Controllers;

use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\StoreOrderItem;
use App\Models\StoreTransactionItem;
use App\Models\SupplierItems;
use App\Models\Wastage;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;
use App\Exports\InventoryMovementReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class InventoryMovementReportController extends Controller
{
    public function index(Request $request)
    {
        ini_set('max_execution_time', 600); // 10 minutes
        ini_set('memory_limit', '1024M');

        $user = Auth::user();
        $filters = $request->only(['date_from', 'date_to', 'branch_id', 'supplier_code', 'search', 'per_page', 'sort_field', 'sort_direction']);
        
        // Defaults
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today('Asia/Manila')->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today('Asia/Manila')->format('Y-m-d');
        $filters['per_page'] = $filters['per_page'] ?? 50;

        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');
        
        $branches = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_code']);

        $suppliers = $this->getSupplierOptions($user);
        $assignedSupplierCodes = $suppliers->pluck('value')->toArray();

        if (!empty($filters['supplier_code']) && !in_array($filters['supplier_code'], $assignedSupplierCodes, true)) {
            unset($filters['supplier_code']);
        }

        if (!$request->has('branch_id') && $branches->isNotEmpty()) {
            $filters['branch_id'] = $branches->first()->id;
        }

        $query = SAPMasterfile::query()
            ->where('is_active', true);

        if (!empty($filters['branch_id'])) {
            $this->applyMovementFilter($query, $filters);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('ItemCode', 'like', "%{$search}%")
                  ->orWhere('ItemDescription', 'like', "%{$search}%");
            });
        }

        $this->applySupplierFilter($query, $filters);

        $allSapItems = $query->get();
        $allMovementData = collect($this->getMovementData($allSapItems, $filters));

        if (!empty($filters['sort_field'])) {
            $sortField = $filters['sort_field'];
            $sortDirection = $filters['sort_direction'] ?? 'asc';
            
            if ($sortDirection === 'desc') {
                $allMovementData = $allMovementData->sortByDesc($sortField, SORT_REGULAR);
            } else {
                $allMovementData = $allMovementData->sortBy($sortField, SORT_REGULAR);
            }
        }

        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $perPage = (int) $filters['per_page'];
        
        $paginatedData = new \Illuminate\Pagination\LengthAwarePaginator(
            $allMovementData->forPage($currentPage, $perPage)->values(),
            $allMovementData->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
        $paginatedData->appends($request->all());

        return Inertia::render('Reports/InventoryMovementReport/Index', [
            'movementData' => $paginatedData->items(),
            'sapItems' => $paginatedData,
            'filters' => $filters,
            'branches' => $branches,
            'suppliers' => $suppliers,
        ]);
    }

    public function exportPdf(Request $request)
    {
        ['filters' => $filters, 'movementData' => $movementData, 'branch' => $branch, 'supplier' => $supplier, 'generatedAt' => $generatedAt]
            = $this->buildExportReport($request);

        $pdf = Pdf::loadView('pdf.inventory-movement-report', [
            'movementData' => $movementData,
            'filters' => $filters,
            'branch' => $branch,
            'supplier' => $supplier,
            'date_generated' => $generatedAt,
            'generated_by' => Auth::user()->full_name,
        ]);

        return $pdf->setPaper('legal', 'landscape')->stream('inventory-movement-report.pdf');
    }

    public function exportExcel(Request $request)
    {
        ['filters' => $filters, 'movementData' => $movementData, 'branch' => $branch, 'supplier' => $supplier, 'generatedAt' => $generatedAt]
            = $this->buildExportReport($request);

        $fileName = 'inventory-movement-report'
            . ($branch ? '-' . Str::slug($branch->branch_code ?: $branch->name) : '')
            . '-' . $filters['date_from'] . '-to-' . $filters['date_to'] . '.xlsx';

        return Excel::download(
            new InventoryMovementReportExport(
                $movementData,
                $filters,
                $branch,
                $supplier,
                Auth::user()->full_name,
                $generatedAt
            ),
            $fileName
        );
    }

    /**
     * Resolve the same filtered dataset both exports render, so the PDF and the
     * Excel file can never disagree about what the report contains.
     */
    private function buildExportReport(Request $request): array
    {
        ini_set('max_execution_time', 600); // 10 minutes
        ini_set('memory_limit', '1024M');

        $user = Auth::user();
        $filters = $request->only(['date_from', 'date_to', 'branch_id', 'supplier_code', 'search']);

        $filters['date_from'] = $filters['date_from'] ?? Carbon::today('Asia/Manila')->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today('Asia/Manila')->format('Y-m-d');

        $assignedSupplierCodes = $this->getSupplierOptions($user)->pluck('value')->toArray();

        if (!empty($filters['supplier_code']) && !in_array($filters['supplier_code'], $assignedSupplierCodes, true)) {
            unset($filters['supplier_code']);
        }

        $query = SAPMasterfile::query()
            ->where('is_active', true);

        if (!empty($filters['branch_id'])) {
            $this->applyMovementFilter($query, $filters);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('ItemCode', 'like', "%{$search}%")
                  ->orWhere('ItemDescription', 'like', "%{$search}%");
            });
        }

        $this->applySupplierFilter($query, $filters);

        $sapItems = $query->get();

        return [
            'filters' => $filters,
            'movementData' => $this->getMovementData($sapItems, $filters),
            'branch' => StoreBranch::find($filters['branch_id']),
            'supplier' => !empty($filters['supplier_code'])
                ? Supplier::where('supplier_code', $filters['supplier_code'])->first()
                : null,
            // Stamped in Asia/Manila explicitly: APP_TIMEZONE is not set in every
            // environment, and a bare now() then prints the report 8 hours behind.
            'generatedAt' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
        ];
    }

    private function applySupplierFilter($query, $filters)
    {
        if (empty($filters['supplier_code']) || $filters['supplier_code'] === 'all') {
            return;
        }

        $supplierCode = $filters['supplier_code'];

        if ($supplierCode === 'CPO') {
            $branchId = $filters['branch_id'] ?? null;
            $dateFrom = $filters['date_from'] ?? null;
            $dateTo = $filters['date_to'] ?? null;

            $query->whereExists(function($sub) use ($supplierCode, $branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_order_items as soi')
                    ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                    ->join('suppliers as s', 'so.supplier_id', '=', 's.id')
                    ->whereColumn('soi.item_code', 'sap_masterfiles.ItemCode')
                    ->where('s.supplier_code', $supplierCode)
                    ->whereNull('so.interco_number');

                if (!empty($branchId)) {
                    $sub->where('so.store_branch_id', $branchId);
                }

                if (!empty($dateFrom) && !empty($dateTo)) {
                    $sub->whereBetween('so.order_date', [$dateFrom, $dateTo]);
                }
            });

            return;
        }

        $query->whereExists(function($sub) use ($supplierCode) {
            $sub->select(DB::raw(1))
                ->from('supplier_items')
                ->whereColumn('supplier_items.ItemCode', 'sap_masterfiles.ItemCode')
                ->where('supplier_items.SupplierCode', $supplierCode)
                ->where('supplier_items.is_active', true);
        });
    }

    private function getSupplierOptions($user)
    {
        $suppliers = $user->suppliers()
            ->where('suppliers.is_active', true)
            ->orderBy('name')
            ->get(['suppliers.supplier_code', 'suppliers.name']);

        if ($suppliers->isEmpty()) {
            $suppliers = Supplier::where('is_active', true)
                ->orderBy('name')
                ->get(['supplier_code', 'name']);
        }

        return $suppliers->map(fn ($supplier) => [
            'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
            'value' => $supplier->supplier_code,
        ])->values();
    }

    private function applyMovementFilter($query, $filters)
    {
        $branchId = $filters['branch_id'];
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];

        $query->where(function($q) use ($branchId, $dateFrom, $dateTo) {
            // Check for procurement activity (Ordered, Committed, or Received) based on order_date
            $q->whereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_order_items as soi')
                    ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                    ->whereColumn('soi.item_code', 'sap_masterfiles.ItemCode')
                    ->where('so.store_branch_id', $branchId)
                    ->whereBetween('so.order_date', [$dateFrom, $dateTo]);
            })
            // Check for sales via BOM based on the POS sales date (order_date), not the import timestamp
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_transaction_items as sti')
                    ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
                    ->where('st.store_branch_id', $branchId)
                    ->whereBetween('st.order_date', [$dateFrom, $dateTo])
                    ->whereExists(function($inner) {
                        $inner->select(DB::raw(1))
                            ->from('pos_masterfiles_bom as b')
                            ->whereColumn('b.ItemCode', 'sap_masterfiles.ItemCode')
                            ->join('pos_masterfiles as pm', 'b.POSCode', '=', 'pm.POSCode')
                            ->whereColumn('pm.id', 'sti.product_id');
                    });
            })
            // Check for wastage
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('wastages')
                    ->join('sap_masterfiles as sap2', 'wastages.sap_masterfile_id', '=', 'sap2.id')
                    ->whereColumn('sap2.ItemCode', 'sap_masterfiles.ItemCode')
                    ->where('store_branch_id', $branchId)
                    ->where('wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                    ->whereBetween('wastages.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            })
            // Check for Interco Outbound (as sending store)
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_order_items as soi')
                    ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                    ->whereColumn('soi.item_code', 'sap_masterfiles.ItemCode')
                    ->where('so.sending_store_branch_id', $branchId)
                    ->whereNotNull('so.interco_number')
                    ->whereBetween('so.order_date', [$dateFrom, $dateTo]);
            })
            // Check for MEC Balances (Beginning or Actual)
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $prevMonth = Carbon::parse($dateFrom)->subMonth();
                $currMonth = Carbon::parse($dateTo);
                
                $sub->select(DB::raw(1))
                    ->from('month_end_count_items as meci')
                    ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
                    ->join('sap_masterfiles as sap2', 'meci.sap_masterfile_id', '=', 'sap2.id')
                    ->whereColumn('sap2.ItemCode', 'sap_masterfiles.ItemCode')
                    ->where('meci.branch_id', $branchId)
                    ->where(function($inner) use ($prevMonth, $currMonth) {
                        $inner->where(function($p) use ($prevMonth) {
                            $p->where('mes.year', $prevMonth->year)
                              ->where('mes.month', $prevMonth->month);
                        })->orWhere(function($c) use ($currMonth) {
                            $c->where('mes.year', $currMonth->year)
                              ->where('mes.month', $currMonth->month);
                        });
                    });
            });
        });
    }


    /**
     * One row per ItemCode, every quantity converted into the item's smallest unit.
     *
     * An item carries several sap_masterfiles rows (one per AltUOM, and since SAP restates
     * packs in a second base, possibly two BaseUOM=AltUOM rows). Orders are in the ordered
     * unit, sales in the BOM unit, wastage in the chosen row's AltUOM and counts in their own
     * uom, so each source is summed per (ItemCode, unit) and converted here. Joining back to
     * sap_masterfiles by ItemCode would count every line once per matching row.
     */
    private function getMovementData($sapItems, $filters)
    {
        $movementData = [];

        if (empty($filters['branch_id']) || empty($sapItems)) {
            return $movementData;
        }

        $branchId = $filters['branch_id'];
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];
        $itemsByCode = collect($sapItems)->filter(fn ($sapItem) => filled($sapItem->ItemCode))->groupBy('ItemCode');
        $sapItemCodes = $itemsByCode->keys()->all();
        $selectedSupplier = !empty($filters['supplier_code']) && $filters['supplier_code'] !== 'all'
            ? Supplier::where('supplier_code', $filters['supplier_code'])->first()
            : null;

        $supplierItems = collect();
        foreach (array_chunk($sapItemCodes, 1000) as $itemCodeChunk) {
            $supplierItemQuery = SupplierItems::with('supplier')
                ->where('is_active', true)
                ->whereIn('ItemCode', $itemCodeChunk);

            if (!empty($filters['supplier_code']) && $filters['supplier_code'] !== 'all') {
                $supplierItemQuery->where('SupplierCode', $filters['supplier_code']);
            }

            $supplierItems = $supplierItems->merge(
                $supplierItemQuery->get(['ItemCode', 'uom', 'SupplierCode'])
            );
        }

        $supplierLookup = $supplierItems
            ->groupBy('ItemCode')
            ->map(fn ($items) => $items
                ->map(fn ($supplierItem) => $supplierItem->supplier?->name ?? $supplierItem->SupplierCode)
                ->filter()
                ->unique()
                ->sort()
                ->implode(', ')
            );

        $metrics = ['ordered', 'committed', 'received', 'sales', 'wastage', 'interco_in', 'interco_out', 'beg_bal', 'actual_mec'];
        $totals = array_fill_keys($metrics, collect());

        $prevMonth = Carbon::parse($dateFrom)->subMonth();
        $begMecSchedule = MonthEndSchedule::where('year', $prevMonth->year)
            ->where('month', $prevMonth->month)
            ->first();

        $currMonth = Carbon::parse($dateTo);
        $currMecSchedule = MonthEndSchedule::where('year', $currMonth->year)
            ->where('month', $currMonth->month)
            ->first();

        $unitKey = fn (string $unitColumn) => "UPPER(LTRIM(RTRIM(COALESCE({$unitColumn}, ''))))";
        $unitSum = fn (string $itemColumn, string $unitColumn, string $qtyExpression) => [
            "{$itemColumn} as item_code",
            DB::raw($unitKey($unitColumn) . ' as unit'),
            DB::raw("SUM({$qtyExpression}) as qty"),
        ];

        // SQL Server limitation: max 2100 parameters. Chunking into 1000 to be safe.
        foreach (array_chunk($sapItemCodes, 1000) as $chunk) {
            $procurement = fn () => DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->whereIn('soi.item_code', $chunk)
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->groupBy('soi.item_code', DB::raw($unitKey('soi.uom')));

            // 1. Ordered (Regular Procurement)
            $totals['ordered'] = $totals['ordered']->merge($procurement()
                ->where('so.store_branch_id', $branchId)
                ->whereNull('so.interco_number')
                ->select($unitSum('soi.item_code', 'soi.uom', 'COALESCE(soi.quantity_approved, 0)'))
                ->get());

            // 1.5 Committed (Regular Procurement)
            $totals['committed'] = $totals['committed']->merge($procurement()
                ->where('so.store_branch_id', $branchId)
                ->whereNull('so.interco_number')
                // Auto-committed orders can have no committer. Use the same
                // eligible statuses as receiving, while retaining explicit line commitments.
                ->where(function ($query) {
                    $query->whereNotNull('soi.committed_by')
                        ->orWhereIn('so.order_status', \App\Http\Services\OrderReceivingService::RECEIVING_STATUSES);
                })
                ->select($unitSum('soi.item_code', 'soi.uom', 'COALESCE(soi.quantity_commited, 0)'))
                ->get());

            // 1.6 Received (Regular Procurement) and 4. Interco Inbound (received by this store)
            foreach (['received' => 'whereNull', 'interco_in' => 'whereNotNull'] as $metric => $intercoClause) {
                $totals[$metric] = $totals[$metric]->merge($procurement()
                    ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
                    ->where('so.store_branch_id', $branchId)
                    ->{$intercoClause}('so.interco_number')
                    ->where('oird.status', 'approved')
                    ->select($unitSum('soi.item_code', 'soi.uom', 'COALESCE(oird.quantity_received, 0)'))
                    ->get());
            }

            // 5. Interco Outbound (Shipped from this store)
            $totals['interco_out'] = $totals['interco_out']->merge($procurement()
                ->where('so.sending_store_branch_id', $branchId)
                ->whereNotNull('so.interco_number')
                ->select($unitSum('soi.item_code', 'soi.uom', 'COALESCE(soi.quantity_commited, 0)'))
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
                ->where('st.store_branch_id', $branchId)
                ->whereBetween('st.order_date', [$dateFrom, $dateTo])
                ->whereIn('bom.ItemCode', $chunk)
                ->select($unitSum('bom.ItemCode', 'bom.BOMUOM', 'COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0)'))
                ->groupBy('bom.ItemCode', DB::raw($unitKey('bom.BOMUOM')))
                ->get());

            // 3. Wastage, in the unit of the masterfile row it was filed against
            $totals['wastage'] = $totals['wastage']->merge(DB::table('wastages')
                ->join('sap_masterfiles as sap', 'wastages.sap_masterfile_id', '=', 'sap.id')
                ->where('wastages.store_branch_id', $branchId)
                ->where('wastages.wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                ->whereIn('sap.ItemCode', $chunk)
                ->whereBetween('wastages.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->select($unitSum('sap.ItemCode', 'sap.AltUOM', 'COALESCE(wastages.approverlvl2_qty, 0)'))
                ->groupBy('sap.ItemCode', DB::raw($unitKey('sap.AltUOM')))
                ->get());

            // 6. MEC Beginning Balance and 7. Actual MEC, in the count's own uom
            foreach (['beg_bal' => $begMecSchedule, 'actual_mec' => $currMecSchedule] as $metric => $schedule) {
                if (!$schedule) {
                    continue;
                }

                $countUnit = "NULLIF(meci.uom, ''), sap.AltUOM";
                $totals[$metric] = $totals[$metric]->merge(DB::table('month_end_count_items as meci')
                    ->join('sap_masterfiles as sap', 'meci.sap_masterfile_id', '=', 'sap.id')
                    ->where('meci.month_end_schedule_id', $schedule->id)
                    ->where('meci.branch_id', $branchId)
                    ->whereIn('sap.ItemCode', $chunk)
                    ->select($unitSum('sap.ItemCode', $countUnit, 'COALESCE(meci.total_qty, 0)'))
                    ->groupBy('sap.ItemCode', DB::raw($unitKey($countUnit)))
                    ->get());
            }
        }

        $totals = array_map(fn ($rows) => $rows->groupBy('item_code'), $totals);

        foreach ($itemsByCode as $itemCode => $rows) {
            [$displayUnit, $unitSizes] = $this->resolveUnits($rows);
            // A line with no unit is taken to be in the stock unit, when the item has only one.
            $stockUnits = $rows->filter(fn ($row) => strcasecmp(trim((string) $row->AltUOM), trim((string) $row->BaseUOM)) === 0)
                ->map(fn ($row) => strtoupper(trim((string) $row->BaseUOM)))
                ->unique();
            $blankUnit = $stockUnits->count() === 1 ? $stockUnits->first() : '';
            $unconverted = [];
            $values = [];
            $procurementSources = [];

            foreach ($metrics as $metric) {
                $values[$metric] = 0.0;

                foreach ($totals[$metric]->get($itemCode, []) as $row) {
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

                $values[$metric] = round($values[$metric], 4);
            }

            $theoretical = $values['beg_bal'] + $values['received'] + $values['interco_in']
                - $values['sales'] - $values['wastage'] - $values['interco_out'];

            $movementData[] = [
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
                'interco_in_qty' => $values['interco_in'],
                'interco_out_qty' => $values['interco_out'],
                'theoretical_qty' => round($theoretical, 4),
                'actual_mec' => $values['actual_mec'],
                'procurement_sources' => $procurementSources,
                // Quantities in a unit with no conversion to the display unit are left out.
                'unconverted_units' => array_values(array_unique($unconverted)),
            ];
        }

        return $movementData;
    }

    /**
     * How many of the item's smallest unit each of its units holds, from the masterfile's
     * conversion rows (AltQty x AltUOM = BaseQty x BaseUOM, e.g. 1000 Gm = 1 Bag).
     *
     * @return array{0: string, 1: array<string, float>} display unit, and size per UPPER unit
     */
    private function resolveUnits($rows): array
    {
        $labels = [];
        $edges = [];

        foreach ($rows as $row) {
            $alt = strtoupper(trim((string) $row->AltUOM));
            $base = strtoupper(trim((string) $row->BaseUOM));

            foreach ([$alt => $row->AltUOM, $base => $row->BaseUOM] as $key => $label) {
                if ($key !== '') {
                    $labels[$key] ??= trim((string) $label);
                    $edges[$key] ??= [];
                }
            }

            if ($alt === '' || $base === '' || $alt === $base || (float) $row->AltQty <= 0 || (float) $row->BaseQty <= 0) {
                continue;
            }

            // One AltUOM holds BaseQty / AltQty of the BaseUOM.
            $factor = (float) $row->BaseQty / (float) $row->AltQty;
            $edges[$alt][$base] = $factor;
            $edges[$base][$alt] = 1 / $factor;
        }

        // Units with no conversion between them form separate groups; report in the
        // largest group, which holds the item's real stock units.
        $best = [];
        $seen = [];
        foreach (array_keys($edges) as $start) {
            if (isset($seen[$start])) {
                continue;
            }

            $sizes = [$start => 1.0];
            $queue = [$start];
            while ($queue) {
                $unit = array_shift($queue);
                foreach ($edges[$unit] as $next => $factor) {
                    if (!isset($sizes[$next])) {
                        // 1 unit = factor next, so next is 1/factor the size of unit.
                        $sizes[$next] = $sizes[$unit] / $factor;
                        $queue[] = $next;
                    }
                }
            }

            $seen += $sizes;
            if (count($sizes) > count($best)) {
                $best = $sizes;
            }
        }

        if (!$best) {
            return ['', []];
        }

        $smallest = min($best);
        $displayKey = array_search($smallest, $best, true);

        return [$labels[$displayKey], array_map(fn ($size) => $size / $smallest, $best)];
    }
}
