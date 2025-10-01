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

        // --- Count Calculation ---
        $forApprovalCount = MonthEndSchedule::query()
            ->whereHas('countItems', function ($q) use ($userBranchIds) {
                $q->whereIn('branch_id', $userBranchIds)
                    ->where('status', 'level1_approved');
            })->count();

        $approvedCount = MonthEndSchedule::query()
            ->whereHas('countItems', function ($q) use ($userBranchIds) {
                $q->whereIn('branch_id', $userBranchIds)
                  ->where('status', 'level2_approved');
            })->count();

        $counts = [
            'for_approval' => $forApprovalCount,
            'approved' => $approvedCount,
        ];

        // --- Main Query ---
        $query = MonthEndSchedule::query()
            ->with(['creator:id,first_name,last_name']);

        if ($tab === 'for_approval') {
            $query->whereHas('countItems', function ($q) use ($userBranchIds) {
                $q->whereIn('branch_id', $userBranchIds)
                    ->where('status', 'level1_approved');
            });
        } else { // approved tab
            $query->whereHas('countItems', function ($q) use ($userBranchIds) {
                $q->whereIn('branch_id', $userBranchIds)
                  ->where('status', 'level2_approved');
            });
        }

        // Filtering
        $query->when($request->input('year'), fn ($q, $year) => $q->where('year', 'like', "%{$year}%"));
        $query->when($request->input('month'), fn ($q, $month) => $q->where('month', 'like', "%{$month}%"));
        $query->when($request->input('calculated_date'), fn ($q, $date) => $q->whereDate('calculated_date', $date));

        if ($tab === 'approved' && $request->input('status')) {
            $status = $request->input('status');
            $query->whereHas('countItems', function ($q) use ($status) {
                $q->where('status', 'like', "%{$status}%");
            });
        }

        $query->when($request->input('creator_name'), function ($q, $name) {
            $q->whereHas('creator', function ($userQuery) use ($name) {
                $userQuery->where(DB::raw("first_name + ' ' + last_name"), 'like', "%{$name}%");
            });
        });

        $query->when($request->input('branch_name'), function ($q, $name) use ($userBranchIds) {
            $q->whereHas('countItems.branch', function ($branchQuery) use ($name, $userBranchIds) {
                $branchQuery->whereIn('id', $userBranchIds)->where('name', 'like', "%{$name}%");
            });
        });

        // Sorting
        $sort = $request->input('sort', 'id');
        $direction = $request->input('direction', 'desc');

        if ($sort === 'creator_name') {
            $query->join('users', 'users.id', '=', 'month_end_schedules.created_by')
                  ->orderBy('users.first_name', $direction)
                  ->select('month_end_schedules.*');
        } else {
            $query->orderBy($sort, $direction);
        }

        $schedules = $query->paginate(15)->withQueryString();

        $schedules->getCollection()->transform(function ($schedule) use ($userBranchIds, $tab) {
            $statusesToQuery = ($tab === 'for_approval')
                ? ['level1_approved']
                : ['level2_approved'];

            $branchData = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->whereIn('branch_id', $userBranchIds)
                ->whereIn('status', $statusesToQuery)
                ->with('branch:id,name')
                ->get()
                ->groupBy('branch.name')
                ->map(function ($items, $branchName) {
                    return [
                        'name' => $branchName,
                        'id' => $items->first()->branch_id,
                        'statuses' => $items->pluck('status')->unique()->values()->all(),
                    ];
                })->values();
            $schedule->branch_data = $branchData;
            return $schedule;
        });

        return Inertia::render('MECApproval2/Index', [
            'schedules' => $schedules,
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
            ->where('status', 'level1_approved')
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
                ->get();

            foreach ($countItems as $item) {
                $productStock = ProductInventoryStock::firstOrNew([
                    'product_inventory_id' => $item->sap_masterfile_id,
                    'store_branch_id' => $item->branch_id,
                ]);

                $oldQuantity = $productStock->exists ? $productStock->quantity : 0;
                $adjustmentQuantity = $item->total_qty - $oldQuantity;

                $productStock->quantity = $item->total_qty;
                $productStock->recently_added = 0;
                $productStock->used = 0;
                $productStock->save();

                if ($adjustmentQuantity != 0) {
                    ProductInventoryStockManager::create([
                        'product_inventory_id' => $item->sap_masterfile_id,
                        'store_branch_id' => $item->branch_id,
                        'quantity' => abs($adjustmentQuantity),
                        'action' => $adjustmentQuantity > 0 ? 'add' : 'out',
                        'transaction_date' => Carbon::now(),
                        'is_stock_adjustment' => true,
                        'is_stock_adjustment_approved' => true,
                        'remarks' => $item->remarks ?? 'Month End Count Adjustment',
                    ]);
                }

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
            return back()->withErrors(['error' => 'Error during Level 2 approval: ' . $e->getMessage()]);
        }
    }
}