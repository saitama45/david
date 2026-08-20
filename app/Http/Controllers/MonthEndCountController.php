<?php

namespace App\Http\Controllers;

use App\Http\Services\MonthEndCountSettingsService;
use App\Imports\MonthEndCountImport;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndCountReopen;
use App\Models\MonthEndCountTemplate;
use App\Models\MonthEndSchedule;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class MonthEndCountController extends Controller
{
    public function __construct(private MonthEndCountSettingsService $settingsService) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $userBranches = $user->store_branches->pluck('name', 'id');
        $userBranchIds = $user->store_branches->pluck('id');

        $settings = $this->settingsService->current();
        $now = Carbon::now('Asia/Manila');
        $today = Carbon::today('Asia/Manila');

        $downloadSchedule = null;
        $uploadSchedule = null;
        $message = 'No month end count scheduled.';
        $branchesAwaitingUpload = collect();
        $uploadedCountsAwaitingSubmission = collect();

        // Download schedule logic: configurable lead time before calculated_date.
        // Fetch candidate schedules that are today or in the future.
        $candidateSchedules = MonthEndSchedule::where('calculated_date', '>=', $today)
            ->orderBy('calculated_date', 'asc')
            ->take(5)
            ->get();

        foreach ($candidateSchedules as $schedule) {
            if ($this->settingsService->isDownloadOpen($today, Carbon::parse($schedule->calculated_date), $settings)) {
                $downloadSchedule = $schedule;
                break;
            }
        }

        // Upload schedule: the most recent past schedule this user can still
        // upload for — either inside the normal window, or via a support-granted
        // reopen on one of their branches.
        $pastSchedules = MonthEndSchedule::where('calculated_date', '<', $today)
            ->orderBy('calculated_date', 'desc')
            ->take(5)
            ->get();

        foreach ($pastSchedules as $schedule) {
            $uploadable = $this->uploadableBranchesFor($schedule, $userBranchIds, $now, $settings);

            if ($uploadable->isNotEmpty()) {
                $uploadSchedule = $schedule;
                $branchesAwaitingUpload = $uploadable;
                break;
            }
        }

        // Explain the upload window whatever state it is in. Without this the
        // page simply renders nothing when the window is shut, which reads to
        // the user as "you have nothing to do" rather than "you are locked out".
        $uploadWindow = $this->describeUploadWindow($now, $today, $userBranchIds, $settings);

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
            $message = 'A month end count is scheduled. Please download the template for your branch.';
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
                DB::raw("STRING_AGG(CAST(meci.status AS NVARCHAR(MAX)), ', ') as statuses")
            )
            ->groupBy('mes.id', 'mes.year', 'mes.month', 'mes.calculated_date', 'sb.id', 'sb.name', 'u.first_name', 'u.last_name');

        // Filtering
        $query->when($request->input('year'), fn ($q, $year) => $q->where('mes.year', 'like', "%{$year}%"));
        $query->when($request->input('month'), fn ($q, $month) => $q->where('mes.month', 'like', "%{$month}%"));
        $query->when($request->input('calculated_date'), fn ($q, $date) => $q->whereDate('mes.calculated_date', $date));
        $query->when($request->input('branch_name'), fn ($q, $name) => $q->where('sb.name', 'like', "%{$name}%"));
        $query->when($request->input('uploader_name'), fn ($q, $name) => $q->where(DB::raw("u.first_name + ' ' + u.last_name"), 'like', "%{$name}%"));
        $query->when($request->input('status'), fn ($q, $status) => $q->havingRaw("STRING_AGG(CAST(meci.status AS NVARCHAR(MAX)), ', ') LIKE ?", ["%{$status}%"]));

        // Sorting
        $sort = $request->input('sort', 'calculated_date');
        $direction = $request->input('direction', 'desc');

        if ($sort === 'uploader_name') {
            $query->orderBy(DB::raw("u.first_name + ' ' + u.last_name"), $direction);
        } elseif ($sort === 'branch_name') {
            $query->orderBy('sb.name', $direction);
        } elseif ($sort === 'statuses') {
            $query->orderBy(DB::raw("STRING_AGG(CAST(meci.status AS NVARCHAR(MAX)), ', ')"), $direction);
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
            'uploadWindow' => $uploadWindow,
            'supportEmail' => config('app.support_email'),
            'userBranches' => $userBranches,
            'branchesAwaitingUpload' => $branchesAwaitingUpload, // Pass this to frontend
            'uploadedCountsAwaitingSubmission' => $uploadedCountsAwaitingSubmission, // New prop
            'transactions' => $transactions,
            'filters' => $request->only(['year', 'month', 'calculated_date', 'status', 'branch_name', 'uploader_name', 'sort', 'direction']),
            'can' => [
                'view_transaction' => $user->can('view month end count transaction'),
                'download_month_end_count_template' => $user->can('download month end count template'),
                'upload_month_end_count_transaction' => $user->can('upload month end count transaction'),
            ],
        ]);
    }

    /**
     * Branches assigned to the user that still owe a count for this schedule.
     */
    private function branchesAwaitingUploadFor(MonthEndSchedule $schedule, $userBranchIds)
    {
        $alreadyUploaded = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
            ->whereIn('branch_id', $userBranchIds)
            ->whereNotIn('status', ['rejected'])
            ->select('branch_id')
            ->distinct()
            ->pluck('branch_id');

        return StoreBranch::whereIn('id', $userBranchIds->diff($alreadyUploaded))->pluck('name', 'id');
    }

    /**
     * Active (unexpired) reopens for these branches on this schedule, keyed by
     * branch id. Anchored to Manila like the rest of the count window maths.
     */
    private function activeReopensFor(MonthEndSchedule $schedule, $branchIds, Carbon $now)
    {
        return MonthEndCountReopen::where('month_end_schedule_id', $schedule->id)
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->mapWithKeys(fn ($r) => [
                $r->branch_id => Carbon::parse($r->reopened_until->format('Y-m-d H:i:s'), 'Asia/Manila'),
            ])
            ->filter(fn ($until) => $now->lte($until));
    }

    /**
     * Branches the user may actually upload for right now: still owing a count,
     * and either inside the normal window or covered by an active reopen.
     */
    private function uploadableBranchesFor(MonthEndSchedule $schedule, $userBranchIds, Carbon $now, array $settings)
    {
        $awaiting = $this->branchesAwaitingUploadFor($schedule, $userBranchIds);

        if ($awaiting->isEmpty()) {
            return $awaiting;
        }

        $calculatedDate = Carbon::parse($schedule->calculated_date, 'Asia/Manila');

        if ($this->settingsService->isUploadOpen($now, $calculatedDate, $settings)) {
            return $awaiting;
        }

        $reopens = $this->activeReopensFor($schedule, $awaiting->keys(), $now);

        return $awaiting->filter(fn ($name, $branchId) => $reopens->has($branchId));
    }

    /**
     * Describe the upload window for the count the user is expected to act on
     * (the most recent past schedule), in whatever state that window is in.
     *
     * States: 'none' (nothing scheduled yet), 'not_yet' (counted, window has
     * not opened), 'open', 'complete' (window open, nothing left to submit)
     * and 'closed' (deadline passed with counts still outstanding).
     */
    private function describeUploadWindow(Carbon $now, Carbon $today, $userBranchIds, array $settings): array
    {
        $schedule = MonthEndSchedule::where('calculated_date', '<', $today)
            ->orderBy('calculated_date', 'desc')
            ->first();

        $base = [
            'rule' => $this->settingsService->describeUploadRule($settings),
            'schedule_label' => null,
            'count_date' => null,
            'opens_at' => null,
            'closes_at' => null,
            'reopened_until' => null,
            'branches_awaiting' => [],
        ];

        if (! $schedule) {
            return array_merge($base, ['state' => 'none']);
        }

        $calculatedDate = Carbon::parse($schedule->calculated_date, 'Asia/Manila');
        $opensAt = $this->settingsService->uploadStart($calculatedDate, $settings);
        $closesAt = $this->settingsService->uploadCutoff($calculatedDate, $settings);
        $awaiting = $this->branchesAwaitingUploadFor($schedule, $userBranchIds);

        // Only branches that are still blocked belong in the notice — a branch
        // support has reopened gets the upload form instead.
        $uploadable = $this->uploadableBranchesFor($schedule, $userBranchIds, $now, $settings);
        $blocked = $awaiting->diffKeys($uploadable);

        // A reopened branch is working to the reopen deadline, not the original
        // cutoff — showing the original would quote a date already in the past.
        $reopenedUntil = $this->activeReopensFor($schedule, $uploadable->keys(), $now)->max();

        $base = array_merge($base, [
            'schedule_label' => $calculatedDate->format('F Y'),
            'count_date' => $calculatedDate->format('M j, Y'),
            'opens_at' => $opensAt->format('M j, Y'),
            'closes_at' => $closesAt ? $closesAt->format('M j, Y \a\t g:i A') : null,
            'reopened_until' => $reopenedUntil?->format('M j, Y \a\t g:i A'),
            'branches_awaiting' => $blocked->values()->all(),
        ]);

        if ($awaiting->isEmpty()) {
            return array_merge($base, ['state' => 'complete']);
        }

        // Nothing blocked means uploading is available; the form covers it.
        if ($blocked->isEmpty()) {
            return array_merge($base, ['state' => 'open']);
        }

        if ($now->lt($opensAt)) {
            return array_merge($base, ['state' => 'not_yet']);
        }

        return array_merge($base, ['state' => 'closed']);
    }

    public function downloadTemplate(Request $request)
    {
        // Fetch only Active records from MonthEndCountTemplate
        $templates = MonthEndCountTemplate::where('is_active', 1)->get();

        $items = collect();

        foreach ($templates as $template) {
            $currentSoh = 0;
            if ($request->has('branch_id')) {
                $sapMasterfile = SAPMasterfile::where('ItemCode', $template->item_code)
                    ->where('AltUOM', $template->uom)
                    ->where('is_active', true)
                    ->first();

                if ($sapMasterfile) {
                    $stock = ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                        ->where('store_branch_id', $request->branch_id)
                        ->first();
                    $currentSoh = $stock ? $stock->quantity : 0;
                }
            }

            $items->push([
                'Item Code' => $template->item_code,
                'Item Name' => $template->item_name,
                'Category 1' => $template->category,
                'Area' => $template->area,
                'Category 2' => $template->category_2,
                'Packaging' => $template->packaging_config,
                'Conversion' => $template->config,
                'Bulk UOM' => $template->uom,
                'Loose UOM' => $template->loose_uom,
                'Current SOH' => $currentSoh,
                'Bulk Qty' => '', // User fillable
                'Loose Qty' => '', // User fillable
                'Remarks' => '', // User fillable
            ]);
        }

        $fileName = 'month_end_count_template_'.Carbon::now()->format('Ymd_His').'.xlsx';

        return Excel::download(new \App\Exports\MonthEndCountDownloadExport($items), $fileName);
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

        // Enforce the configurable upload window (start offset .. cutoff) in Asia/Manila.
        $settings = $this->settingsService->current();
        $now = Carbon::now('Asia/Manila');
        $calculatedDate = Carbon::parse($schedule->calculated_date, 'Asia/Manila');
        $uploadStart = $this->settingsService->uploadStart($calculatedDate, $settings);
        $uploadCutoff = $this->settingsService->uploadCutoff($calculatedDate, $settings);

        // A support-granted reopen overrides the window for this branch only.
        $reopenedUntil = $this->activeReopensFor($schedule, [$branch->id], $now)->get($branch->id);

        if ($reopenedUntil) {
            Log::info('MonthEndCountController@upload: Branch has an active reopen.', [
                'branch_id' => $branch->id,
                'reopened_until' => $reopenedUntil->toDateTimeString(),
            ]);
        }

        if (! $reopenedUntil && $now->lt($uploadStart)) {
            Log::warning('MonthEndCountController@upload: Upload date is before the allowed start.', [
                'calculated_date' => $calculatedDate->toDateString(),
                'upload_start' => $uploadStart->toDateTimeString(),
                'now_manila' => $now->toDateTimeString(),
            ]);

            return back()->withErrors(['error' => 'File can only be uploaded starting '.$uploadStart->format('M j, Y').'.']);
        }

        if (! $reopenedUntil && $uploadCutoff && $now->gt($uploadCutoff)) {
            Log::warning('MonthEndCountController@upload: Upload date is past the cutoff.', [
                'calculated_date' => $calculatedDate->toDateString(),
                'upload_cutoff' => $uploadCutoff->toDateTimeString(),
                'now_manila' => $now->toDateTimeString(),
            ]);

            return back()->withErrors(['error' => 'The upload window for this count closed on '.$uploadCutoff->format('M j, Y g:i A').'.']);
        }
        Log::info('MonthEndCountController@upload: Date validation passed.');

        // Check for branch-specific upload validation
        // Allow upload if this branch hasn't uploaded for this schedule yet
        $existingUpload = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $branch->id)
            ->whereNotIn('status', ['rejected'])
            ->first();

        if ($existingUpload) {
            Log::warning('MonthEndCountController@upload: Branch already uploaded for this schedule.', [
                'schedule_id' => $schedule->id,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'existing_status' => $existingUpload->status,
            ]);

            return back()->withErrors(['error' => "The branch \"{$branch->name}\" has already uploaded for this schedule. Multiple uploads from the same branch are not allowed."]);
        }

        // Additional schedule-level validation - only block if schedule is expired (not level2_approved since different branches can upload)
        if ($schedule->status === 'expired') {
            Log::warning('MonthEndCountController@upload: Schedule is expired.', ['schedule_id' => $schedule->id, 'status' => $schedule->status]);

            return back()->withErrors(['error' => 'This schedule has expired and is no longer open for uploads.']);
        }
        Log::info('MonthEndCountController@upload: Branch-specific validation passed.');

        try {
            Log::info('MonthEndCountController@upload: Starting Excel import.', ['branch_id' => $branch->id, 'schedule_id' => $schedule->id]);
            Excel::import(new MonthEndCountImport($branch->id, $schedule->id), $request->file('file'));
            Log::info('MonthEndCountController@upload: Excel import completed.');

            // REMOVED: $schedule->status = 'uploaded'; $schedule->save();
            // Schedule status is now managed by the approval process, not individual uploads.

            Log::info('MonthEndCountController@upload: Import transaction completed. Redirecting to review page.');

            return redirect()->route('month-end-count.review', ['schedule' => $schedule->id, 'branch' => $branch->id])->with('success', 'Month end count uploaded successfully. Please review and submit for approval.');
        } catch (Exception $e) {
            Log::error('MonthEndCountController@upload: Error during upload process.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return back()->withErrors(['error' => 'Error processing file: '.$e->getMessage()]);
        }
    }

    public function review(MonthEndSchedule $schedule, StoreBranch $branch)
    {
        if (Auth::user()->cannot('view month end count transaction')) {
            abort(403, 'This action is unauthorized.');
        }

        // Ensure the user has access to this branch
        $user = Auth::user();
        if (! $user->store_branches->contains($branch->id)) {
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
        if (! $user->store_branches->contains($branch->id)) {
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
            Cache::forget('user_notifications_v5_'.Auth::id());
            $this->clearMonthEndNotificationCachesForBranch($branch->id);

            return redirect()->route('month-end-count.index')->with('success', 'Count submitted for Level 1 approval.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('MonthEndCountController@submitForApproval: Error submitting items for approval.', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return back()->withErrors(['error' => 'Error submitting items for approval: '.$e->getMessage()]);
        }
    }

    private function clearMonthEndNotificationCachesForBranch(int $branchId): void
    {
        $userIds = \App\Models\UserAssignedStoreBranch::where('store_branch_id', $branchId)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        $affectedUserIds = \App\Models\User::whereIn('id', $userIds)
            ->get()
            ->filter(function ($user) {
                return $user->can('approve month end count level 1')
                    || $user->can('view month end count approvals');
            })
            ->pluck('id');

        foreach ($affectedUserIds as $userId) {
            Cache::forget('user_notifications_v5_'.$userId);
        }
    }

    public function updateReviewItem(Request $request, MonthEndCountItem $monthEndCountItem)
    {
        $user = Auth::user();
        if (! $user->store_branches->contains($monthEndCountItem->branch_id)) {
            abort(403, 'You do not have access to this branch.');
        }

        if (! $user->can('edit month end count items')) {
            abort(403, 'You do not have permission to edit count items.');
        }

        if ($request->has('config') && ! empty($monthEndCountItem->packaging_config)) {
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
