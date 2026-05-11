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
use Barryvdh\DomPDF\Facade\Pdf;

class InventoryMovementReportController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $filters = $request->only(['date_from', 'date_to', 'branch_id', 'supplier_code', 'search', 'per_page']);
        
        // Defaults
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
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
            ->where('is_active', true)
            ->whereColumn('AltUOM', 'BaseUOM');

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

        $sapItems = $query->paginate($filters['per_page'])->withQueryString();
        
        $movementData = $this->getMovementData($sapItems->items(), $filters);

        return Inertia::render('Reports/InventoryMovementReport/Index', [
            'movementData' => $movementData,
            'sapItems' => $sapItems,
            'filters' => $filters,
            'branches' => $branches,
            'suppliers' => $suppliers,
        ]);
    }

    public function exportPdf(Request $request)
    {
        ini_set('max_execution_time', 600); // 10 minutes
        ini_set('memory_limit', '1024M');

        $user = Auth::user();
        $filters = $request->only(['date_from', 'date_to', 'branch_id', 'supplier_code', 'search']);
        
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');

        $assignedSupplierCodes = $this->getSupplierOptions($user)->pluck('value')->toArray();

        if (!empty($filters['supplier_code']) && !in_array($filters['supplier_code'], $assignedSupplierCodes, true)) {
            unset($filters['supplier_code']);
        }

        $query = SAPMasterfile::query()
            ->where('is_active', true)
            ->whereColumn('AltUOM', 'BaseUOM');

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
        $movementData = $this->getMovementData($sapItems, $filters);
        $branch = StoreBranch::find($filters['branch_id']);
        $supplier = !empty($filters['supplier_code'])
            ? Supplier::where('supplier_code', $filters['supplier_code'])->first()
            : null;

        $pdf = Pdf::loadView('pdf.inventory-movement-report', [
            'movementData' => $movementData,
            'filters' => $filters,
            'branch' => $branch,
            'supplier' => $supplier,
            'date_generated' => Carbon::now()->format('Y-m-d H:i:s'),
            'generated_by' => Auth::user()->full_name,
        ]);

        return $pdf->setPaper('legal', 'landscape')->stream('inventory-movement-report.pdf');
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
                ->whereColumn('supplier_items.uom', 'sap_masterfiles.AltUOM')
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
            // Check for sales via BOM
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_transaction_items as sti')
                    ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
                    ->where('st.store_branch_id', $branchId)
                    ->whereBetween('st.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
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

    private function getMovementData($sapItems, $filters)
    {
        $movementData = [];
        
        if (empty($filters['branch_id']) || empty($sapItems)) {
            return $movementData;
        }

        $branchId = $filters['branch_id'];
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];
        $sapIds = collect($sapItems)->pluck('id')->toArray();
        $sapItemCodes = collect($sapItems)->pluck('ItemCode')->filter()->unique()->values()->toArray();
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
            ->groupBy(fn ($supplierItem) => $supplierItem->ItemCode . '|' . strtoupper((string) $supplierItem->uom))
            ->map(fn ($items) => $items
                ->map(fn ($supplierItem) => $supplierItem->supplier?->name ?? $supplierItem->SupplierCode)
                ->filter()
                ->unique()
                ->sort()
                ->implode(', ')
            );

        // SQL Server limitation: max 2100 parameters. Chunking into 1000 to be safe.
        $chunks = array_chunk($sapIds, 1000);

        $orderedData = collect();
        $committedData = collect();
        $receivedData = collect();
        $salesData = collect();
        $wastageData = collect();
        $intercoInData = collect();
        $intercoOutData = collect();
        $begBalData = collect();
        $actualMecData = collect();

        $prevMonth = Carbon::parse($dateFrom)->subMonth();
        $begMecSchedule = MonthEndSchedule::where('year', $prevMonth->year)
            ->where('month', $prevMonth->month)
            ->first();

        $currMonth = Carbon::parse($dateTo);
        $currMecSchedule = MonthEndSchedule::where('year', $currMonth->year)
            ->where('month', $currMonth->month)
            ->first();

        foreach ($chunks as $chunk) {
            $chunkItemCodes = SAPMasterfile::whereIn('id', $chunk)->pluck('ItemCode')->toArray();

            // 1. Ordered (Regular Procurement)
            $orderedChunk = DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.store_branch_id', $branchId)
                ->whereNull('so.interco_number')
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->whereIn('sap.id', $chunk)
                ->select('sap.ItemCode', DB::raw('SUM(COALESCE(soi.quantity_approved, 0)) as total_ordered'))
                ->groupBy('sap.ItemCode')
                ->get();
            $orderedData = $orderedData->merge($orderedChunk);

            // 1.5 Committed (Regular Procurement)
            $committedChunk = DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.store_branch_id', $branchId)
                ->whereNull('so.interco_number')
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->whereNotNull('soi.committed_by')
                ->whereIn('sap.id', $chunk)
                ->select('sap.ItemCode', DB::raw('SUM(COALESCE(soi.quantity_commited, 0)) as total_committed'))
                ->groupBy('sap.ItemCode')
                ->get();
            $committedData = $committedData->merge($committedChunk);

            // 1.6 Received (Regular Procurement)
            $receivedChunk = DB::table('ordered_item_receive_dates as oird')
                ->join('store_order_items as soi', 'oird.store_order_item_id', '=', 'soi.id')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.store_branch_id', $branchId)
                ->whereNull('so.interco_number')
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->where('oird.status', 'approved')
                ->whereIn('sap.id', $chunk)
                ->select('sap.ItemCode', DB::raw('SUM(COALESCE(oird.quantity_received, 0)) as total_received'))
                ->groupBy('sap.ItemCode')
                ->get();
            $receivedData = $receivedData->merge($receivedChunk);

            // 2. Sales
            $salesChunk = DB::table('store_transaction_items as sti')
                ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
                ->join('pos_masterfiles as pm', 'sti.product_id', '=', 'pm.id')
                ->join('pos_masterfiles_bom as bom', 'pm.POSCode', '=', 'bom.POSCode')
                ->join('sap_masterfiles as sap', 'bom.ItemCode', '=', 'sap.ItemCode')
                ->where('st.store_branch_id', $branchId)
                ->whereBetween('st.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->whereIn('sap.id', $chunk)
                ->select('sap.ItemCode',
                    DB::raw('SUM(COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0)) as total_sales'))
                ->groupBy('sap.ItemCode')
                ->get();
            $salesData = $salesData->merge($salesChunk);

            // 3. Wastage
            $wastageChunk = Wastage::join('sap_masterfiles as sap', 'wastages.sap_masterfile_id', '=', 'sap.id')
                ->where('wastages.store_branch_id', $branchId)
                ->where('wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                ->whereIn('sap.ItemCode', $chunkItemCodes)
                ->whereBetween('wastages.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->select('sap.ItemCode', DB::raw('SUM(COALESCE(approverlvl2_qty, 0)) as total_wastage'))
                ->groupBy('sap.ItemCode')
                ->get();
            $wastageData = $wastageData->merge($wastageChunk);

            // 4. Interco Inbound (Received by this store)
            $intercoInChunk = DB::table('ordered_item_receive_dates as oird')
                ->join('store_order_items as soi', 'oird.store_order_item_id', '=', 'soi.id')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.store_branch_id', $branchId)
                ->whereNotNull('so.interco_number')
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->where('oird.status', 'approved')
                ->whereIn('sap.id', $chunk)
                ->select('sap.ItemCode', DB::raw('SUM(COALESCE(oird.quantity_received, 0)) as total_received'))
                ->groupBy('sap.ItemCode')
                ->get();
            $intercoInData = $intercoInData->merge($intercoInChunk);

            // 5. Interco Outbound (Shipped from this store)
            $intercoOutChunk = DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.sending_store_branch_id', $branchId)
                ->whereNotNull('so.interco_number')
                ->whereBetween('so.order_date', [$dateFrom, $dateTo])
                ->whereIn('sap.id', $chunk)
                ->select('sap.ItemCode', DB::raw('SUM(COALESCE(soi.quantity_commited, 0)) as total_committed'))
                ->groupBy('sap.ItemCode')
                ->get();
            $intercoOutData = $intercoOutData->merge($intercoOutChunk);

            // 6. MEC Beginning Balance
            if ($begMecSchedule) {
                $begBalChunk = MonthEndCountItem::join('sap_masterfiles as sap', 'month_end_count_items.sap_masterfile_id', '=', 'sap.id')
                    ->where('month_end_schedule_id', $begMecSchedule->id)
                    ->where('branch_id', $branchId)
                    ->whereIn('sap.ItemCode', $chunkItemCodes)
                    ->select('sap.ItemCode', DB::raw('SUM(total_qty) as total_qty'))
                    ->groupBy('sap.ItemCode')
                    ->get();
                $begBalData = $begBalData->merge($begBalChunk);
            }

            // 7. Actual MEC
            if ($currMecSchedule) {
                $actualMecChunk = MonthEndCountItem::join('sap_masterfiles as sap', 'month_end_count_items.sap_masterfile_id', '=', 'sap.id')
                    ->where('month_end_schedule_id', $currMecSchedule->id)
                    ->where('branch_id', $branchId)
                    ->whereIn('sap.ItemCode', $chunkItemCodes)
                    ->select('sap.ItemCode', DB::raw('SUM(total_qty) as total_qty'))
                    ->groupBy('sap.ItemCode')
                    ->get();
                $actualMecData = $actualMecData->merge($actualMecChunk);
            }
        }

        $orderedData = $orderedData->keyBy('ItemCode');
        $committedData = $committedData->keyBy('ItemCode');
        $receivedData = $receivedData->keyBy('ItemCode');
        $salesData = $salesData->keyBy('ItemCode');
        $wastageData = $wastageData->keyBy('ItemCode');
        $intercoInData = $intercoInData->keyBy('ItemCode');
        $intercoOutData = $intercoOutData->keyBy('ItemCode');
        $begBalData = $begBalData->keyBy('ItemCode');
        $actualMecData = $actualMecData->keyBy('ItemCode');

        foreach ($sapItems as $sapItem) {
            $itemCode = $sapItem->ItemCode;
            
            $orderedRow = $orderedData->get($itemCode);
            $committedRow = $committedData->get($itemCode);
            $receivedRow = $receivedData->get($itemCode);
            $sales = $salesData->get($itemCode);
            $wastage = $wastageData->get($itemCode);
            $intercoIn = $intercoInData->get($itemCode);
            $intercoOut = $intercoOutData->get($itemCode);
            $begBal = $begBalData->get($itemCode);
            $actualMec = $actualMecData->get($itemCode);

            $ordered = $orderedRow ? (float)$orderedRow->total_ordered : 0;
            $committed = $committedRow ? (float)$committedRow->total_committed : 0;
            $received = $receivedRow ? (float)$receivedRow->total_received : 0;
            $salesQty = $sales ? (float)$sales->total_sales : 0;
            $wastageQty = $wastage ? (float)$wastage->total_wastage : 0;
            $intercoInQty = $intercoIn ? (float)$intercoIn->total_received : 0;
            $intercoOutQty = $intercoOut ? (float)$intercoOut->total_committed : 0;
            $begBalQty = $begBal ? (float)$begBal->total_qty : 0;
            $actualMecQty = $actualMec ? (float)$actualMec->total_qty : 0;

            $theoretical = $begBalQty + $received + $intercoInQty - $salesQty - $wastageQty - $intercoOutQty;

            $movementData[] = [
                'supplier' => ($filters['supplier_code'] ?? null) === 'CPO'
                    ? ($selectedSupplier?->name ?? 'CPO')
                    : $supplierLookup->get($itemCode . '|' . strtoupper((string) $sapItem->AltUOM), ''),
                'sap_code' => $sapItem->ItemCode,
                'item_description' => $sapItem->ItemDescription,
                'uom' => $sapItem->AltUOM,
                'ordered_qty' => $ordered,
                'committed_qty' => $committed,
                'received_qty' => $received,
                'beg_bal_qty' => $begBalQty,
                'sales_qty' => $salesQty,
                'wastage_qty' => $wastageQty,
                'interco_in_qty' => $intercoInQty,
                'interco_out_qty' => $intercoOutQty,
                'theoretical_qty' => $theoretical,
                'actual_mec' => $actualMecQty,
            ];
        }

        return $movementData;
    }
}
