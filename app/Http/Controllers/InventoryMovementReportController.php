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
use App\Exports\InventoryMovementDetailExport;
use App\Exports\InventoryMovementReportExport;
use App\Http\Services\InventoryMovementAdjustmentService;
use App\Http\Services\InventoryMovementDetailService;
use App\Http\Services\InventoryMovementService;
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

    /**
     * The transactions behind one figure of the report, for the popup a click on it opens:
     * one item, one column, one page of lines, each with the link of its reference.
     */
    public function details(Request $request)
    {
        [$sapRows, $branchId, $filters, $metric, $page] = $this->detailRequest($request);

        return response()->json(app(InventoryMovementDetailService::class)->details($sapRows, $branchId, $filters, $metric, $page));
    }

    /**
     * The popup's list as an Excel file: every line of the figure, not only the page of
     * them the popup is showing.
     */
    public function exportDetailsExcel(Request $request)
    {
        ini_set('max_execution_time', 600); // 10 minutes
        ini_set('memory_limit', '1024M');

        [$sapRows, $branchId, $filters, $metric] = $this->detailRequest($request);

        $branch = StoreBranch::find($branchId);
        $item = $sapRows->first();

        $fileName = 'inventory-movement-' . Str::slug(InventoryMovementDetailService::LABELS[$metric][0])
            . '-' . Str::slug($item->ItemCode)
            . ($branch ? '-' . Str::slug($branch->branch_code ?: $branch->name) : '')
            . '-' . $filters['date_from'] . '-to-' . $filters['date_to'] . '.xlsx';

        return Excel::download(
            new InventoryMovementDetailExport(
                app(InventoryMovementDetailService::class)->details($sapRows, $branchId, $filters, $metric, 1, null),
                $metric,
                (string) $item->ItemCode,
                (string) $item->ItemDescription,
                $branch,
                $filters,
                Auth::user()->full_name,
                Carbon::now('Asia/Manila')->format('Y-m-d H:i:s')
            ),
            $fileName
        );
    }

    /**
     * What a request for the transactions behind one figure asks for, checked: the popup
     * and its Excel export read the same item, store, period and column.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: int, 2: array{date_from: string, date_to: string}, 3: string, 4: int}
     */
    private function detailRequest(Request $request): array
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'sap_code' => ['required', 'string', 'max:255'],
            'metric' => ['required', \Illuminate\Validation\Rule::in(InventoryMovementDetailService::METRICS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // Only a store the user is assigned to, as the report's own store list.
        $user = Auth::user();
        $user->load('store_branches');
        abort_unless($user->store_branches->contains('id', (int) $validated['branch_id']), 403, 'You are not assigned to this store.');

        // The item's active rows, the ones the report converts its units with.
        $sapRows = SAPMasterfile::where('is_active', true)->where('ItemCode', $validated['sap_code'])->orderBy('id')->get();
        abort_if($sapRows->isEmpty(), 404);

        return [
            $sapRows,
            (int) $validated['branch_id'],
            ['date_from' => Carbon::parse($validated['date_from'])->toDateString(), 'date_to' => Carbon::parse($validated['date_to'])->toDateString()],
            $validated['metric'],
            (int) ($validated['page'] ?? 1),
        ];
    }

    /**
     * Enters, or replaces, the adjustment of one item's Variance with the reason for it.
     * It belongs to the store and the month of the report's To Date, and posts no stock.
     */
    public function saveAdjustment(Request $request, InventoryMovementAdjustmentService $adjustments)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'date_to' => ['required', 'date'],
            'sap_code' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'between:-999999999,999999999'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'quantity.required' => 'Enter the adjustment quantity. Enter 0 to take an adjustment back.',
            'quantity.numeric' => 'The adjustment must be a number.',
            'reason.required' => 'Enter the reason for the adjustment.',
            'reason.max' => 'The reason may not be longer than 500 characters.',
        ]);

        // Only a store the user is assigned to, as the report's own store list.
        $user = Auth::user();
        $user->load('store_branches');
        abort_unless($user->store_branches->contains('id', (int) $validated['branch_id']), 403, 'You are not assigned to this store.');

        $branch = StoreBranch::findOrFail((int) $validated['branch_id']);

        // The item's active rows: the report shows it in their unit, and so is the adjustment.
        $sapRows = SAPMasterfile::where('is_active', true)->where('ItemCode', $validated['sap_code'])->orderBy('id')->get();
        abort_if($sapRows->isEmpty(), 404);

        $adjustment = $adjustments->save(
            $branch,
            (string) $sapRows->first()->ItemCode,
            ['date_to' => Carbon::parse($validated['date_to'])->toDateString()],
            (float) $validated['quantity'],
            $validated['reason'],
            app(InventoryMovementService::class)->itemUnits($sapRows)[0],
            $user->id
        );

        return response()->json(['adjustment' => $adjustments->fields($adjustment)]);
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
            // Check for a wasted Sub-Prep whose BOM uses the item
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('wastages')
                    ->join('pos_masterfiles as pm2', 'wastages.pos_masterfile_id', '=', 'pm2.id')
                    ->join('pos_masterfiles_bom as b2', 'b2.POSCode', '=', 'pm2.POSCode')
                    ->whereColumn('b2.ItemCode', 'sap_masterfiles.ItemCode')
                    ->whereColumn('b2.entity_id', 'pm2.entity_id')
                    ->whereNull('wastages.sap_masterfile_id')
                    ->where('wastages.store_branch_id', $branchId)
                    ->where('wastages.wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
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
     * The per-item movement rows, from the service the Month End Count template shares,
     * with the report's own Adjustment and Final Variance added to each.
     */
    private function getMovementData($sapItems, $filters)
    {
        return app(InventoryMovementAdjustmentService::class)->apply(
            app(InventoryMovementService::class)->movementData($sapItems, $filters),
            $filters['branch_id'] ?? null,
            $filters
        );
    }
}
