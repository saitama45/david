<?php

namespace App\Http\Controllers;

use App\Models\MonthEndSchedule;
use App\Models\MonthEndCountItem;
use App\Models\StoreBranch;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Exception;
use Illuminate\Support\Facades\DB;

class MECApproval2Controller extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $userBranchIds = $user->store_branches->pluck('id');
        $tab = $request->input('tab', 'for_approval');

        // --- Main Query ---
        $statusesToQuery = ($tab === 'for_approval')
            ? ['level1_approved']
            : ['level2_approved'];

        $query = DB::table('month_end_count_items as meci')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('users as u', 'mes.created_by', '=', 'u.id')
            ->whereIn('meci.branch_id', $userBranchIds)
            ->whereIn('meci.status', $statusesToQuery)
            ->select(
                'mes.id as schedule_id',
                'mes.year',
                'mes.month',
                'mes.calculated_date',
                'sb.id as branch_id',
                'sb.name as branch_name',
                DB::raw("u.first_name + ' ' + u.last_name as creator_name"),
                'meci.status'
            )
            ->distinct();

        // Filtering
        $query->when($request->input('year'), fn ($q, $year) => $q->where('mes.year', 'like', "%{$year}%"));
        $query->when($request->input('month'), fn ($q, $month) => $q->where('mes.month', 'like', "%{$month}%"));
        $query->when($request->input('calculated_date'), fn ($q, $date) => $q->whereDate('mes.calculated_date', $date));
        $query->when($request->input('status'), fn ($q, $status) => $q->where('meci.status', 'like', "%{$status}%"));
        $query->when($request->input('creator_name'), fn ($q, $name) => $q->where(DB::raw("u.first_name + ' ' + u.last_name"), 'like', "%{$name}%"));
        $query->when($request->input('branch_name'), fn ($q, $name) => $q->where('sb.name', 'like', "%{$name}%"));

        // Sorting
        $sort = $request->input('sort', 'id');
        $direction = $request->input('direction', 'desc');
        $sort_map = [
            'id' => 'mes.id',
            'year' => 'mes.year',
            'month' => 'mes.month',
            'calculated_date' => 'mes.calculated_date',
            'status' => 'meci.status',
            'creator_name' => 'creator_name',
            'branch_name' => 'sb.name',
        ];
        if (array_key_exists($sort, $sort_map)) {
            $query->orderBy($sort_map[$sort], $direction);
        }

        $approvals = $query->paginate(15)->withQueryString();

        // --- Count Calculation ---
        $forApprovalCount = DB::table('month_end_count_items')
            ->whereIn('branch_id', $userBranchIds)
            ->where('status', 'level1_approved')
            ->distinct()
            ->count('branch_id');

        $approvedCount = DB::table('month_end_count_items')
            ->whereIn('branch_id', $userBranchIds)
            ->where('status', 'level2_approved')
            ->distinct()
            ->count('branch_id');

        $counts = [
            'for_approval' => $forApprovalCount,
            'approved' => $approvedCount,
        ];

        return Inertia::render('MECApproval2/Index', [
            'approvals' => $approvals,
            'filters' => $request->only(['year', 'month', 'calculated_date', 'status', 'creator_name', 'branch_name', 'sort', 'direction', 'tab']),
            'tab' => $tab,
            'counts' => $counts,
        ]);
    }

    public function show($scheduleId, $branchId)
    {
        $schedule = MonthEndSchedule::findOrFail($scheduleId);
        $branch = StoreBranch::findOrFail($branchId);
        $user = Auth::user();

        if (!$user->can('approve month end count level 2')) {
             abort(403, 'You do not have access to this page.');
        }

        $countItems = MonthEndCountItem::with(['sapMasterfile', 'uploader:id,first_name,last_name', 'level1Approver:id,first_name,last_name'])
            ->where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $branch->id)
            ->orderBy('item_name')
            ->get();

        return Inertia::render('MECApproval2/Show', [
            'schedule' => $schedule->only(['id', 'year', 'month', 'calculated_date', 'status']),
            'branch' => $branch->only(['id', 'name']),
            'countItems' => $countItems,
            'canApproveLevel2' => $user->can('approve month end count level 2'),
        ]);
    }

    public function approveLevel2($scheduleId, $branchId)
    {
        $schedule = MonthEndSchedule::findOrFail($scheduleId);
        $branch = StoreBranch::findOrFail($branchId);

        if (!Auth::user()->can('approve month end count level 2')) {
            abort(403, 'You do not have permission to approve at Level 2.');
        }

        // Time-sensitive approval condition
        $deadline = Carbon::parse($schedule->calculated_date)->addDays(2)->endOfDay();
        if (Carbon::now('Asia/Manila')->greaterThan($deadline)) {
            MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->where('branch_id', $branch->id)
                ->where('status', 'level1_approved')
                ->update(['status' => 'expired']);
            return back()->withErrors(['error' => 'Approval deadline has passed. Items marked as expired.']);
        }

        DB::beginTransaction();
        try {
            $countItems = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->where('branch_id', $branch->id)
                ->where('status', 'level1_approved')
                ->with('sapMasterfile') // Eager load the original masterfile to get the ItemCode
                ->get();

            // Group items by the ItemCode of their related SAP Masterfile to aggregate quantities
            $groupedItems = $countItems->filter(function ($item) {
                return $item->sapMasterfile !== null;
            })->groupBy('sapMasterfile.ItemCode');

            foreach ($groupedItems as $itemCode => $items) {
                // 1. Calculate the total aggregated quantity for this group (same ItemCode).
                $totalAggregatedQty = $items->sum('total_qty');

                // 2. Find the single target masterfile for this group.
                // The target has the same ItemCode, but its BaseUOM = AltUOM.
                $targetSapMasterfile = DB::table('sap_masterfiles')
                    ->where('ItemCode', $itemCode)
                    ->whereColumn('BaseUOM', 'AltUOM')
                    ->first();

                if ($targetSapMasterfile) {
                    // 3. Update ProductInventoryStock with the final aggregated quantity.
                    $productStock = ProductInventoryStock::firstOrNew([
                        'product_inventory_id' => $targetSapMasterfile->id,
                        'store_branch_id' => $branch->id,
                    ]);

                    $currentSOH = $productStock->exists ? $productStock->quantity : 0;
                    $adjustmentQuantity = $totalAggregatedQty - $currentSOH;

                    $productStock->quantity = $totalAggregatedQty; // Set to the final aggregated count
                    $productStock->recently_added = 0;
                    $productStock->used = 0;
                    $productStock->save();

                    // 4. Create ONE stock manager entry for the total adjustment for this product.
                    if ($adjustmentQuantity != 0) {
                        $remarkText = "Month End Count Approved for the month of " . Carbon::createFromDate(null, $schedule->month)->format('F') . " {$schedule->year}";
                        $remarkData = "MEC_REF::{$schedule->id},{$branch->id}";

                        ProductInventoryStockManager::create([
                            'product_inventory_id' => $targetSapMasterfile->id,
                            'store_branch_id' => $branch->id,
                            'quantity' => abs($adjustmentQuantity),
                            'action' => $adjustmentQuantity > 0 ? 'add' : 'out',
                            'transaction_date' => Carbon::now(),
                            'unit_cost' => 0,
                            'total_cost' => 0,
                            'is_stock_adjustment' => true,
                            'is_stock_adjustment_approved' => true,
                            'remarks' => "{$remarkText}||{$remarkData}",
                        ]);
                    }
                }
            }

            // 5. After processing all groups, update the status of all original items.
            foreach ($countItems as $item) {
                $item->update([
                    'status' => 'level2_approved',
                    'level2_approved_by' => Auth::id(),
                    'level2_approved_at' => Carbon::now(),
                ]);
            }

            $pendingItems = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->whereIn('status', ['uploaded', 'pending_level1_approval', 'level1_approved'])
                ->exists();

            if (!$pendingItems) {
                $schedule->status = 'level2_approved';
                $schedule->save();
            }

            DB::commit();
            return redirect()->route('month-end-count-approvals-level2.index')->with('success', 'Level 2 approval completed and inventory updated.');
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Error during Level 2 approval: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Error during Level 2 approval: ' . $e->getMessage()]);
        }
    }
}