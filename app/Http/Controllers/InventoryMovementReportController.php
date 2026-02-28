<?php

namespace App\Http\Controllers;

use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use App\Models\StoreTransactionItem;
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
        $filters = $request->only(['date_from', 'date_to', 'branch_id', 'search', 'per_page']);
        
        // Defaults
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['per_page'] = $filters['per_page'] ?? 50;

        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');
        
        $branches = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_code']);

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

        $sapItems = $query->paginate($filters['per_page'])->withQueryString();
        
        $movementData = $this->getMovementData($sapItems->items(), $filters);

        return Inertia::render('Reports/InventoryMovementReport/Index', [
            'movementData' => $movementData,
            'sapItems' => $sapItems,
            'filters' => $filters,
            'branches' => $branches,
        ]);
    }

    public function exportPdf(Request $request)
    {
        ini_set('max_execution_time', 600); // 10 minutes
        ini_set('memory_limit', '1024M');

        $filters = $request->only(['date_from', 'date_to', 'branch_id', 'search']);
        
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');

        $query = SAPMasterfile::query()->where('is_active', true);

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

        $sapItems = $query->get();
        $movementData = $this->getMovementData($sapItems, $filters);
        $branch = StoreBranch::find($filters['branch_id']);

        $pdf = Pdf::loadView('pdf.inventory-movement-report', [
            'movementData' => $movementData,
            'filters' => $filters,
            'branch' => $branch,
            'date_generated' => Carbon::now()->format('Y-m-d H:i:s'),
            'generated_by' => Auth::user()->full_name,
        ]);

        return $pdf->setPaper('legal', 'landscape')->stream('inventory-movement-report.pdf');
    }

    private function applyMovementFilter($query, $filters)
    {
        $branchId = $filters['branch_id'];
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];

        $query->where(function($q) use ($branchId, $dateFrom, $dateTo) {
            // Check for received orders
            $q->whereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_order_items as soi')
                    ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                    ->whereColumn('soi.item_code', 'sap_masterfiles.ItemCode')
                    ->where('so.store_branch_id', $branchId)
                    ->where('so.order_status', \App\Enum\OrderStatus::RECEIVED->value)
                    ->whereBetween('so.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
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
                    ->whereColumn('sap_masterfile_id', 'sap_masterfiles.id')
                    ->where('store_branch_id', $branchId)
                    ->where('wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                    ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            })
            // Check for Interco Inbound
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_order_items as soi')
                    ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                    ->whereColumn('soi.item_code', 'sap_masterfiles.ItemCode')
                    ->where('so.store_branch_id', $branchId)
                    ->whereNotNull('so.interco_number')
                    ->where('so.interco_status', \App\Enums\IntercoStatus::RECEIVED->value)
                    ->whereBetween('so.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            })
            // Check for Interco Outbound
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $sub->select(DB::raw(1))
                    ->from('store_order_items as soi')
                    ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                    ->whereColumn('soi.item_code', 'sap_masterfiles.ItemCode')
                    ->where('so.sending_store_branch_id', $branchId)
                    ->whereNotNull('so.interco_number')
                    ->whereIn('so.interco_status', [\App\Enums\IntercoStatus::COMMITTED->value, \App\Enums\IntercoStatus::IN_TRANSIT->value, \App\Enums\IntercoStatus::RECEIVED->value])
                    ->whereBetween('so.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            })
            // Check for MEC Balances (Beginning or Actual)
            ->orWhereExists(function($sub) use ($branchId, $dateFrom, $dateTo) {
                $prevMonth = Carbon::parse($dateFrom)->subMonth();
                $currMonth = Carbon::parse($dateTo);
                
                $sub->select(DB::raw(1))
                    ->from('month_end_count_items as meci')
                    ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
                    ->whereColumn('meci.sap_masterfile_id', 'sap_masterfiles.id')
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

        // SQL Server limitation: max 2100 parameters. Chunking into 1000 to be safe.
        $chunks = array_chunk($sapIds, 1000);

        $ordersData = collect();
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
            // 1. Orders
            $ordersChunk = DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.store_branch_id', $branchId)
                ->where('so.order_status', \App\Enum\OrderStatus::RECEIVED->value)
                ->whereBetween('so.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->whereIn('sap.id', $chunk)
                ->select('sap.id as sap_id',
                    DB::raw('SUM(COALESCE(soi.quantity_ordered, 0)) as ordered'),
                    DB::raw('SUM(COALESCE(soi.quantity_commited, 0)) as committed'),
                    DB::raw('SUM(COALESCE(soi.quantity_received, 0)) as received'))
                ->groupBy('sap.id')
                ->get();
            $ordersData = $ordersData->merge($ordersChunk);

            // 2. Sales
            $salesChunk = DB::table('store_transaction_items as sti')
                ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
                ->join('pos_masterfiles as pm', 'sti.product_id', '=', 'pm.id')
                ->join('pos_masterfiles_bom as bom', 'pm.POSCode', '=', 'bom.POSCode')
                ->join('sap_masterfiles as sap', 'bom.ItemCode', '=', 'sap.ItemCode')
                ->where('st.store_branch_id', $branchId)
                ->whereBetween('st.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->whereIn('sap.id', $chunk)
                ->select('sap.id as sap_id',
                    DB::raw('SUM(COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0)) as total_sales'))
                ->groupBy('sap.id')
                ->get();
            $salesData = $salesData->merge($salesChunk);

            // 3. Wastage
            $wastageChunk = Wastage::where('store_branch_id', $branchId)
                ->where('wastage_status', \App\Enums\WastageStatus::APPROVED_LVL2->value)
                ->whereIn('sap_masterfile_id', $chunk)
                ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->select('sap_masterfile_id', DB::raw('SUM(COALESCE(approverlvl2_qty, 0)) as total_wastage'))
                ->groupBy('sap_masterfile_id')
                ->get();
            $wastageData = $wastageData->merge($wastageChunk);

            // 4. Interco Inbound
            $intercoInChunk = DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.store_branch_id', $branchId)
                ->whereNotNull('so.interco_number')
                ->where('so.interco_status', \App\Enums\IntercoStatus::RECEIVED->value)
                ->whereBetween('so.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->whereIn('sap.id', $chunk)
                ->select('sap.id as sap_id', DB::raw('SUM(COALESCE(soi.quantity_received, 0)) as total_received'))
                ->groupBy('sap.id')
                ->get();
            $intercoInData = $intercoInData->merge($intercoInChunk);

            // 5. Interco Outbound
            $intercoOutChunk = DB::table('store_order_items as soi')
                ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
                ->join('sap_masterfiles as sap', 'soi.item_code', '=', 'sap.ItemCode')
                ->where('so.sending_store_branch_id', $branchId)
                ->whereNotNull('so.interco_number')
                ->whereIn('so.interco_status', [\App\Enums\IntercoStatus::COMMITTED->value, \App\Enums\IntercoStatus::IN_TRANSIT->value, \App\Enums\IntercoStatus::RECEIVED->value])
                ->whereBetween('so.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->whereIn('sap.id', $chunk)
                ->select('sap.id as sap_id', DB::raw('SUM(COALESCE(soi.quantity_commited, 0)) as total_committed'))
                ->groupBy('sap.id')
                ->get();
            $intercoOutData = $intercoOutData->merge($intercoOutChunk);

            // 6. MEC Beginning Balance
            if ($begMecSchedule) {
                $begBalChunk = MonthEndCountItem::where('month_end_schedule_id', $begMecSchedule->id)
                    ->where('branch_id', $branchId)
                    ->whereIn('sap_masterfile_id', $chunk)
                    ->select('sap_masterfile_id', 'total_qty')
                    ->get();
                $begBalData = $begBalData->merge($begBalChunk);
            }

            // 7. Actual MEC
            if ($currMecSchedule) {
                $actualMecChunk = MonthEndCountItem::where('month_end_schedule_id', $currMecSchedule->id)
                    ->where('branch_id', $branchId)
                    ->whereIn('sap_masterfile_id', $chunk)
                    ->select('sap_masterfile_id', 'total_qty')
                    ->get();
                $actualMecData = $actualMecData->merge($actualMecChunk);
            }
        }

        $ordersData = $ordersData->keyBy('sap_id');
        $salesData = $salesData->keyBy('sap_id');
        $wastageData = $wastageData->keyBy('sap_masterfile_id');
        $intercoInData = $intercoInData->keyBy('sap_id');
        $intercoOutData = $intercoOutData->keyBy('sap_id');
        $begBalData = $begBalData->keyBy('sap_masterfile_id');
        $actualMecData = $actualMecData->keyBy('sap_masterfile_id');

        foreach ($sapItems as $sapItem) {
            $sapId = $sapItem->id;
            
            $orders = $ordersData->get($sapId);
            $sales = $salesData->get($sapId);
            $wastage = $wastageData->get($sapId);
            $intercoIn = $intercoInData->get($sapId);
            $intercoOut = $intercoOutData->get($sapId);
            $begBal = $begBalData->get($sapId);
            $actualMec = $actualMecData->get($sapId);

            $ordered = $orders ? (float)$orders->ordered : 0;
            $committed = $orders ? (float)$orders->committed : 0;
            $received = $orders ? (float)$orders->received : 0;
            $salesQty = $sales ? (float)$sales->total_sales : 0;
            $wastageQty = $wastage ? (float)$wastage->total_wastage : 0;
            $intercoInQty = $intercoIn ? (float)$intercoIn->total_received : 0;
            $intercoOutQty = $intercoOut ? (float)$intercoOut->total_committed : 0;
            $begBalQty = $begBal ? (float)$begBal->total_qty : 0;
            $actualMecQty = $actualMec ? (float)$actualMec->total_qty : null;

            $theoretical = $begBalQty + $received + $intercoInQty - $salesQty - $wastageQty - $intercoOutQty;

            $movementData[] = [
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
