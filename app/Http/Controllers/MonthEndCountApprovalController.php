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
use Illuminate\Support\Facades\Log;

class MonthEndCountApprovalController extends Controller
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
                    ->where('status', 'pending_level1_approval');
            })->count();

        $approvedCount = MonthEndSchedule::query()
            ->whereHas('countItems', function ($q) use ($userBranchIds) {
                $q->whereIn('branch_id', $userBranchIds)
                  ->whereIn('status', ['level1_approved', 'level2_approved']);
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
                    ->where('status', 'pending_level1_approval');
            });
        } else { // approved tab
            $query->whereHas('countItems', function ($q) use ($userBranchIds) {
                $q->whereIn('branch_id', $userBranchIds)
                  ->whereIn('status', ['level1_approved', 'level2_approved']);
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
        } elseif ($sort === 'branch_name') {
            $subQuery = StoreBranch::select('name')
                ->join('month_end_count_items', 'store_branches.id', '=', 'month_end_count_items.branch_id')
                ->whereColumn('month_end_count_items.month_end_schedule_id', 'month_end_schedules.id')
                ->whereIn('month_end_count_items.branch_id', $userBranchIds)
                ->when($tab === 'for_approval', function($q) {
                    $q->where('month_end_count_items.status', 'pending_level1_approval');
                })
                ->orderBy('name')
                ->limit(1);
            $query->orderBy($subQuery, $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $schedules = $query->paginate(15)->withQueryString();

        $schedules->getCollection()->transform(function ($schedule) use ($userBranchIds, $tab) {
            if ($tab === 'for_approval') {
                $branchesWithItems = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                    ->whereIn('branch_id', $userBranchIds)
                    ->where('status', 'pending_level1_approval')
                    ->with('branch:id,name')
                    ->get()
                    ->pluck('branch')
                    ->unique('id')
                    ->map(fn($branch) => ['id' => $branch->id, 'name' => $branch->name])
                    ->values();
                $schedule->branches_awaiting_approval = $branchesWithItems;
            } else {
                $branchData = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                    ->whereIn('branch_id', $userBranchIds)
                    ->whereIn('status', ['level1_approved', 'level2_approved'])
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
            }
            return $schedule;
        });

        return Inertia::render('MonthEndCountApproval/Index', [
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

        // If a user has approval permissions, they might not be directly associated with the branch.
        // In this case, we bypass the branch ownership check.
        // For other users, we enforce that they must be assigned to the branch.
        if (!$user->can('approve month end count level 1') && !$user->can('approve month end count level 2')) {
            $user->load('store_branches');
            if (!$user->store_branches->contains($branch->id)) {
                abort(403, 'You do not have access to this branch.');
            }
        }

        $countItems = MonthEndCountItem::with(['sapMasterfile', 'uploader:id,first_name,last_name', 'level1Approver:id,first_name,last_name', 'level2Approver:id,first_name,last_name'])
            ->where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $branch->id)
            ->whereIn('status', ['pending_level1_approval', 'level1_approved'])
            ->orderBy('item_name')
            ->get();

        return Inertia::render('MonthEndCountApproval/Show', [
            'schedule' => [
                'id' => $schedule->id,
                'year' => $schedule->year,
                'month' => $schedule->month,
                'calculated_date' => $schedule->calculated_date ? $schedule->calculated_date->toDateString() : null,
                'status' => $schedule->status,
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'countItems' => $countItems,
            'canApproveLevel1' => Auth::user()->can('approve month end count level 1'),
            'canApproveLevel2' => Auth::user()->can('approve month end count level 2'),
            'canEditItems' => Auth::user()->can('edit month end count approval items'),
        ]);
    }

    public function updateItem(Request $request, MonthEndCountItem $monthEndCountItem)
    {
        // Ensure user has permission to edit
        if (!Auth::user()->can('edit month end count approval items')) {
            abort(403, 'You do not have permission to edit count items.');
        }

        if ($request->has('config') && !empty($monthEndCountItem->packaging_config)) {
            return redirect()->back()->withErrors(['error' => 'Config cannot be edited when Packaging Config has a value.']);
        }

        // Allow editing only for items awaiting level 1 approval
        if ($monthEndCountItem->status !== 'pending_level1_approval') {
            return redirect()->back()->withErrors(['error' => 'Item can only be edited while awaiting Level 1 approval.']);
        }

        $validated = $request->validate([
            'bulk_qty' => 'nullable|numeric|min:0',
            'loose_qty' => 'nullable|numeric|min:0',
            'loose_uom' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:1000',
            'config' => 'nullable|numeric|gt:0',
        ]);

        // Apply the validated updates to the model instance
        $monthEndCountItem->fill($validated);

        // Recalculate total_qty
        $bulkQty = (float) $monthEndCountItem->bulk_qty;
        $looseQty = (float) $monthEndCountItem->loose_qty;
        $config = (float) $monthEndCountItem->config;

        if ($config > 0) {
            $monthEndCountItem->total_qty = $bulkQty + ($looseQty / $config);
        } else {
            $monthEndCountItem->total_qty = $bulkQty + $looseQty;
        }

        // Save the model with updated fields and recalculated total_qty
        $monthEndCountItem->save();

        return redirect()->back()->with('success', 'Item updated successfully.');
    }

    public function approveLevel1($scheduleId, $branchId)
    {
        $schedule = MonthEndSchedule::findOrFail($scheduleId);
        $branch = StoreBranch::findOrFail($branchId);

        if (!Auth::user()->can('approve month end count level 1')) {
            abort(403, 'You do not have permission to approve at Level 1.');
        }

        DB::beginTransaction();
        try {
            // Update all items for this schedule/branch from 'pending_level1_approval' to 'level1_approved'
            $updatedCount = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->where('branch_id', $branch->id)
                ->where('status', 'pending_level1_approval')
                ->update([
                    'status' => 'level1_approved',
                    'level1_approved_by' => Auth::id(),
                    'level1_approved_at' => Carbon::now(),
                ]);

            if ($updatedCount === 0) {
                DB::rollBack();
                return redirect()->back()->withErrors(['error' => 'No items found awaiting Level 1 approval.']);
            }

            // Check if any items for the entire schedule are still pending, and update schedule status if not.
            $areAnyItemsStillPending = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->whereIn('status', ['uploaded', 'pending_level1_approval'])
                ->exists();

            if (!$areAnyItemsStillPending) {
                $schedule->status = 'level1_approved';
                $schedule->save();
            }

            DB::commit();
            return redirect()->route('month-end-count-approvals.index')->with('success', 'Level 1 approval completed.');
        } catch (Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Error during Level 1 approval: ' . $e->getMessage()]);
        }
    }

    public function approveLevel2(MonthEndSchedule $schedule, StoreBranch $branch)
    {
        if (!Auth::user()->can('approve month end count level 2')) {
            abort(403, 'You do not have permission to approve at Level 2.');
        }

        // Ensure schedule is in 'level1_approved' status
        if ($schedule->status !== 'level1_approved') {
            return back()->withErrors(['error' => 'Schedule is not in Level 1 approved status.']);
        }

        // Time-sensitive approval condition
        $deadline = Carbon::parse($schedule->calculated_date)->addDays(2)->endOfDay();
        if (Carbon::now('Asia/Manila')->greaterThan($deadline)) {
            // Mark items as expired if not approved within deadline
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
                // Update ProductInventoryStock
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

                // Log adjustment in ProductInventoryStockManager
                if ($adjustmentQuantity != 0) {
                    ProductInventoryStockManager::create([
                        'product_inventory_id' => $item->sap_masterfile_id,
                        'store_branch_id' => $item->branch_id,
                        'quantity' => abs($adjustmentQuantity),
                        'action' => $adjustmentQuantity > 0 ? 'add' : 'out',
                        'transaction_date' => Carbon::now(),
                        'is_stock_adjustment' => true,
                        'is_stock_adjustment_approved' => true, // Auto-approved for month end count
                        'remarks' => $item->remarks ?? 'Month End Count Adjustment',
                    ]);
                }

                // Update item status
                $item->update([
                    'status' => 'level2_approved',
                    'level2_approved_by' => Auth::id(),
                    'level2_approved_at' => Carbon::now(),
                ]);
            }

            // Update the schedule status if all items for this schedule are now level2_approved
            $pendingItems = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
                ->whereIn('status', ['uploaded', 'level1_approved'])
                ->exists();

            if (!$pendingItems) {
                $schedule->status = 'level2_approved';
                $schedule->save();
            }

            DB::commit();
            return redirect()->back()->with('success', 'Level 2 approval completed and inventory updated.');
        } catch (Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Error during Level 2 approval: ' . $e->getMessage()]);
        }
    }
}
