<?php

namespace App\Http\Controllers;

use App\Models\MonthEndSchedule;
use App\Models\MonthEndCountItem;
use App\Models\StoreBranch;
use App\Models\SupplierItems;
use App\Models\SAPMasterfile;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\MonthEndCountTemplateExport;
use App\Imports\MonthEndCountImport;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonthEndCountController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $userBranches = $user->store_branches->pluck('name', 'id');
        $userBranchIds = $user->store_branches->pluck('id');

        $today = Carbon::today('Asia/Manila');
        $yesterday = Carbon::yesterday('Asia/Manila');

        $downloadSchedule = null;
        $uploadSchedule = null;
        $message = 'No month end count scheduled for today or yesterday.';
        $branchesAwaitingUpload = collect(); // Initialize as empty collection
        $uploadedCountsAwaitingSubmission = collect(); // New: For uploaded counts awaiting user submission

        // Check for a global schedule for today (for download)
        $downloadSchedule = MonthEndSchedule::whereDate('calculated_date', $today)
            ->where('status', 'pending')
            ->first();

        // Check for a global schedule for yesterday (for upload)
        $globalUploadSchedule = MonthEndSchedule::whereDate('calculated_date', $yesterday)
            ->whereIn('status', ['pending', 'uploaded', 'level1_approved']) // Allow uploads as long as schedule is not fully completed/closed
            ->first();

        if ($globalUploadSchedule) {
            $allUserBranchIds = $user->store_branches->pluck('id');

            // Find branches that have any non-rejected item for this schedule
            $branchesWithNonRejectedItems = MonthEndCountItem::where('month_end_schedule_id', $globalUploadSchedule->id)
                ->whereIn('branch_id', $allUserBranchIds)
                ->whereNotIn('status', ['rejected']) // Exclude rejected items
                ->select('branch_id')
                ->distinct()
                ->pluck('branch_id');

            // Branches that still need to upload are those assigned to the user
            // AND do not have any non-rejected items for this schedule.
            $branchesAwaitingUploadIds = $allUserBranchIds->diff($branchesWithNonRejectedItems);

            $branchesAwaitingUpload = StoreBranch::whereIn('id', $branchesAwaitingUploadIds)->pluck('name', 'id');

            if ($branchesAwaitingUpload->isNotEmpty()) {
                $uploadSchedule = $globalUploadSchedule; // Set uploadSchedule if there are branches still needing to upload
            }
        }

        // NEW: Get uploaded counts by the current user that are still in 'uploaded' status
        $uploadedCountsAwaitingSubmission = MonthEndCountItem::with(['schedule', 'branch'])
            ->where('created_by', Auth::id())
            ->whereIn('branch_id', $userBranchIds)
            ->where('status', 'uploaded')
            ->select('month_end_schedule_id', 'branch_id')
            ->distinct()
            ->get()
            ->map(function ($item) {
                return [
                    'schedule_id' => $item->month_end_schedule_id,
                    'branch_id' => $item->branch_id,
                    'schedule_year' => $item->schedule->year,
                    'schedule_month' => $item->schedule->month,
                    'branch_name' => $item->branch->name,
                ];
            });

        if ($downloadSchedule) {
            $message = 'A month end count is scheduled for today. Please download the template for your branch.';
        } elseif ($uploadSchedule) {
            $message = 'A month end count was scheduled for yesterday. Please upload the completed file for your remaining branches.';
        } elseif ($uploadedCountsAwaitingSubmission->isNotEmpty()) {
            $message = 'You have uploaded counts awaiting your review and submission for approval.';
        } else {
            $message = 'No pending month end count actions for your branches.';
        }

        $query = DB::table('month_end_count_items as meci')
            ->join('month_end_schedules as mes', 'meci.month_end_schedule_id', '=', 'mes.id')
            ->join('store_branches as sb', 'meci.branch_id', '=', 'sb.id')
            ->join('users as u', 'meci.created_by', '=', 'u.id')
            ->whereIn('meci.branch_id', $userBranchIds)
            ->select(
                'mes.id as schedule_id',
                'mes.year',
                'mes.month',
                'mes.calculated_date',
                'sb.id as branch_id',
                'sb.name as branch_name',
                DB::raw("u.first_name + ' ' + u.last_name as uploader_name"),
                DB::raw("STRING_AGG(meci.status, ', ') as statuses")
            )
            ->groupBy('mes.id', 'mes.year', 'mes.month', 'mes.calculated_date', 'sb.id', 'sb.name', 'u.first_name', 'u.last_name');

        // Filtering
        $query->when($request->input('year'), fn ($q, $year) => $q->where('mes.year', 'like', "%{$year}%"));
        $query->when($request->input('month'), fn ($q, $month) => $q->where('mes.month', 'like', "%{$month}%"));
        $query->when($request->input('calculated_date'), fn ($q, $date) => $q->whereDate('mes.calculated_date', $date));
        $query->when($request->input('branch_name'), fn ($q, $name) => $q->where('sb.name', 'like', "%{$name}%"));
        $query->when($request->input('uploader_name'), fn ($q, $name) => $q->where(DB::raw("u.first_name + ' ' + u.last_name"), 'like', "%{$name}%"));
        $query->when($request->input('status'), fn ($q, $status) => $q->havingRaw("STRING_AGG(meci.status, ', ') LIKE ?", ["%{$status}%"]));


        // Sorting
        $sort = $request->input('sort', 'calculated_date');
        $direction = $request->input('direction', 'desc');

        if ($sort === 'uploader_name') {
            $query->orderBy(DB::raw("u.first_name + ' ' + u.last_name"), $direction);
        } elseif ($sort === 'branch_name') {
            $query->orderBy('sb.name', $direction);
        } elseif ($sort === 'statuses') {
            $query->orderBy(DB::raw("STRING_AGG(meci.status, ', ')"), $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        $transactions = $query->paginate(15)->withQueryString();

        return Inertia::render('MonthEndCount/Index', [
            'downloadSchedule' => $downloadSchedule ? [
                'id' => $downloadSchedule->id,
                'calculated_date' => $downloadSchedule->calculated_date->toDateString(),
                'year' => $downloadSchedule->year,
                'month' => $downloadSchedule->month,
            ] : null,
            'uploadSchedule' => $uploadSchedule ? [
                'id' => $uploadSchedule->id,
                'calculated_date' => $uploadSchedule->calculated_date->toDateString(),
                'year' => $uploadSchedule->year,
                'month' => $uploadSchedule->month,
            ] : null,
            'message' => $message,
            'userBranches' => $userBranches,
            'branchesAwaitingUpload' => $branchesAwaitingUpload, // Pass this to frontend
            'uploadedCountsAwaitingSubmission' => $uploadedCountsAwaitingSubmission, // New prop
            'transactions' => $transactions,
            'filters' => $request->only(['year', 'month', 'calculated_date', 'status', 'branch_name', 'uploader_name', 'sort', 'direction']),
            'can' => [
                'view_transaction' => $user->can('view month end count transaction'),
            ]
        ]);
    }

    public function downloadTemplate(Request $request)
    {
        $request->validate([
            'schedule_id' => 'required|exists:month_end_schedules,id',
            'branch_id' => 'required|exists:store_branches,id',
        ]);

        $schedule = MonthEndSchedule::findOrFail($request->schedule_id);
        $branch = StoreBranch::findOrFail($request->branch_id);

        // Ensure the download is happening on the calculated date
        if ($schedule->calculated_date->toDateString() !== Carbon::today()->toDateString()) {
            return back()->withErrors(['error' => 'Template can only be downloaded on the scheduled date.']);
        }

        // Ensure the schedule is pending
        if ($schedule->status !== 'pending') {
            return back()->withErrors(['error' => 'This schedule is no longer pending.']);
        }

        // Fetch all active SupplierItems
        $items = SupplierItems::where('is_active', true)->get()->map(function ($item) {
            // Attempt to get the SAPMasterfile for the item's UOM
            $sapMasterfile = $item->sap_master_file; // This uses the accessor on SupplierItems

            return [
                'ItemCode' => $item->ItemCode,
                'item_name' => $item->item_name,
                'packaging_config' => $item->packaging_config,
                'config' => $item->config,
                'uom' => $item->uom,
                'bulk_qty' => '', // User fillable
                'loose_qty' => '', // User fillable
                'loose_uom' => '', // User fillable
                'remarks' => '', // User fillable
                'total_qty' => '', // User fillable
                'sap_masterfile_id' => $sapMasterfile ? $sapMasterfile->id : null, // Hidden field for upload
            ];
        });

        $fileName = 'month_end_count_template_' . $branch->name . '_' . $schedule->calculated_date->format('Ymd') . '.xlsx';

        return Excel::download(new MonthEndCountTemplateExport($items), $fileName);
    }

    public function upload(Request $request)
    {
        Log::info('MonthEndCountController@upload: Request received.');
        $request->validate([
            'schedule_id' => 'required|exists:month_end_schedules,id',
            'branch_id' => 'required|exists:store_branches,id',
            'file' => 'required|file|mimes:xlsx,xls',
        ]);
        Log::info('MonthEndCountController@upload: Validation passed.');

        $schedule = MonthEndSchedule::findOrFail($request->schedule_id);
        $branch = StoreBranch::findOrFail($request->branch_id);
        Log::info('MonthEndCountController@upload: Schedule and Branch found.', ['schedule_id' => $schedule->id, 'branch_id' => $branch->id]);

        // Ensure the upload is happening the day after the calculated date
        if ($schedule->calculated_date->addDay()->toDateString() !== Carbon::today()->toDateString()) {
            Log::warning('MonthEndCountController@upload: Upload date mismatch.', ['calculated_date' => $schedule->calculated_date->toDateString(), 'today' => Carbon::today()->toDateString()]);
            return back()->withErrors(['error' => 'File can only be uploaded the day after the scheduled count.']);
        }
        Log::info('MonthEndCountController@upload: Date validation passed.');

        // Ensure the schedule is open for uploads
        $allowed_statuses = ['pending', 'uploaded', 'level1_approved'];
        if (!in_array($schedule->status, $allowed_statuses)) {
            Log::warning('MonthEndCountController@upload: Schedule not open for upload.', ['schedule_id' => $schedule->id, 'status' => $schedule->status]);
            return back()->withErrors(['error' => 'This schedule is no longer open for uploads.']);
        }
        Log::info('MonthEndCountController@upload: Status validation passed.');

        DB::beginTransaction();
        try {
            Log::info('MonthEndCountController@upload: Starting Excel import.', ['branch_id' => $branch->id, 'schedule_id' => $schedule->id]);
            Excel::import(new MonthEndCountImport($branch->id, $schedule->id), $request->file('file'));
            Log::info('MonthEndCountController@upload: Excel import completed.');

            // REMOVED: $schedule->status = 'uploaded'; $schedule->save();
            // Schedule status is now managed by the approval process, not individual uploads.

            DB::commit();
            Log::info('MonthEndCountController@upload: DB transaction committed. Redirecting to review page.');
            return redirect()->route('month-end-count.review', ['schedule' => $schedule->id, 'branch' => $branch->id])->with('success', 'Month end count uploaded successfully. Please review and submit for approval.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('MonthEndCountController@upload: Error during upload process.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Error processing file: ' . $e->getMessage()]);
        }
    }

    public function review(MonthEndSchedule $schedule, StoreBranch $branch)
    {
        if (Auth::user()->cannot('view month end count transaction')) {
            abort(403, 'This action is unauthorized.');
        }

        // Ensure the user has access to this branch
        $user = Auth::user();
        if (!$user->store_branches->contains($branch->id)) {
            abort(403, 'You do not have access to this branch.');
        }

        // Ensure the schedule is pending and items are in 'uploaded' status for this branch
        $countItems = MonthEndCountItem::with(['sapMasterfile', 'uploader:id,first_name,last_name'])
            ->where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $branch->id)
            ->orderBy('item_name')
            ->get();

        if ($countItems->isEmpty()) {
            return redirect()->route('month-end-count.index')->with('error', 'No uploaded items found for review for this schedule and branch.');
        }

        return Inertia::render('MonthEndCount/Review', [
            'schedule' => [
                'id' => $schedule->id,
                'year' => $schedule->year,
                'month' => $schedule->month,
                'calculated_date' => $schedule->calculated_date->toDateString(),
                'status' => $schedule->status,
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'countItems' => $countItems,
            'canEditItems' => Auth::user()->can('edit month end count items'),
        ]);
    }

    public function submitForApproval(Request $request, MonthEndSchedule $schedule, StoreBranch $branch)
    {
        // Ensure the user has access to this branch
        $user = Auth::user();
        if (!$user->store_branches->contains($branch->id)) {
            abort(403, 'You do not have access to this branch.');
        }

        // Ensure items are in 'uploaded' status before submitting for approval
        $itemsToApprove = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $branch->id)
            ->where('status', 'uploaded')
            ->get();

        if ($itemsToApprove->isEmpty()) {
            return back()->withErrors(['error' => 'No uploaded items found to submit for approval.']);
        }

        DB::beginTransaction();
        try {
            foreach ($itemsToApprove as $item) {
                // Recalculate total_qty before submission
                $bulkQty = (float) $item->bulk_qty;
                $looseQty = (float) $item->loose_qty;
                $config = (float) $item->config;

                if ($config > 0) {
                    $item->total_qty = $bulkQty + ($looseQty / $config);
                } else {
                    $item->total_qty = $bulkQty + $looseQty;
                }

                $item->status = 'pending_level1_approval';
                $item->save();
            }
            DB::commit();
            return redirect()->route('month-end-count.index')->with('success', 'Count submitted for Level 1 approval.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('MonthEndCountController@submitForApproval: Error submitting items for approval.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Error submitting items for approval: ' . $e->getMessage()]);
        }
    }

    public function updateReviewItem(Request $request, MonthEndCountItem $monthEndCountItem)
    {
        $user = Auth::user();
        if (!$user->store_branches->contains($monthEndCountItem->branch_id)) {
            abort(403, 'You do not have access to this branch.');
        }

        if (!$user->can('edit month end count items')) {
            abort(403, 'You do not have permission to edit count items.');
        }

        if ($request->has('config') && !empty($monthEndCountItem->packaging_config)) {
            return redirect()->back()->withErrors(['error' => 'Config cannot be edited when Packaging Config has a value.']);
        }

        if ($monthEndCountItem->status !== 'uploaded') {
            return redirect()->back()->withErrors(['error' => 'Item can only be edited before it is submitted for approval.']);
        }

        $validated = $request->validate([
            'bulk_qty' => 'nullable|numeric|min:0',
            'loose_qty' => 'nullable|numeric|min:0',
            'loose_uom' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:1000',
            'config' => 'nullable|numeric|gt:0',
        ]);

        $monthEndCountItem->fill($validated);

        $bulkQty = (float) $monthEndCountItem->bulk_qty;
        $looseQty = (float) $monthEndCountItem->loose_qty;
        $config = (float) $monthEndCountItem->config;

        if ($config > 0) {
            $monthEndCountItem->total_qty = $bulkQty + ($looseQty / $config);
        } else {
            $monthEndCountItem->total_qty = $bulkQty + $looseQty;
        }

        $monthEndCountItem->save();

        return redirect()->back()->with('success', 'Item updated successfully.');
    }
}
