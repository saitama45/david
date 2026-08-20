<?php

namespace App\Http\Controllers;

use App\Http\Services\MonthEndCountSettingsService;
use App\Models\MonthEndCountReopen;
use App\Models\MonthEndCountSetting;
use App\Models\MonthEndSchedule;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MonthEndScheduleController extends Controller
{
    public function __construct(private MonthEndCountSettingsService $settingsService) {}

    public function index(Request $request)
    {
        $selectedYear = $request->input('year');
        if (! $selectedYear) {
            $selectedYear = Carbon::now('Asia/Manila')->year;
        }

        $schedules = MonthEndSchedule::with('creator:id,first_name,last_name')
            ->where('year', $selectedYear)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate(15);

        $scheduleIds = $schedules->pluck('id');
        $totalActiveStores = StoreBranch::where('is_active', true)->count();

        $progressData = DB::table('month_end_count_items')
            ->whereIn('month_end_schedule_id', $scheduleIds)
            ->select('month_end_schedule_id', 'status', DB::raw('count(DISTINCT branch_id) as count'))
            ->groupBy('month_end_schedule_id', 'status')
            ->get()
            ->groupBy('month_end_schedule_id');

        $schedules->getCollection()->transform(function ($schedule) use ($progressData, $totalActiveStores) {
            $schedule->total_stores = $totalActiveStores;
            $schedule->progress = $progressData->get($schedule->id, collect())->keyBy('status')->map(fn ($item) => (int) $item->count);

            return $schedule;
        });

        return Inertia::render('MonthEndSchedule/Index', [
            'schedules' => $schedules,
            'filters' => ['year' => $selectedYear],
            'settings' => $this->settingsService->current(),
            'can' => [
                'create_month_end_schedules' => Auth::user()->can('create month end schedules'),
                'edit_month_end_schedules' => Auth::user()->can('edit month end schedules'),
                'delete_month_end_schedules' => Auth::user()->can('delete month end schedules'),
                'manage_month_end_count_settings' => Auth::user()->can('manage month end count settings'),
                'reopen_month_end_count' => Auth::user()->can('reopen month end count'),
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'download_lead_days' => 'required|integer|min:0|max:90',
            'download_lead_unit' => 'required|in:business,calendar',
            'block_on_weekends' => 'required|boolean',
            'upload_start_days' => 'required|integer|min:0|max:90',
            'upload_start_unit' => 'required|in:business,calendar',
            'upload_cutoff_enabled' => 'required|boolean',
            'upload_cutoff_days' => 'nullable|integer|min:0|max:90|required_if:upload_cutoff_enabled,true',
            'upload_cutoff_unit' => 'required|in:business,calendar',
            'upload_cutoff_time' => 'nullable|date_format:H:i|required_if:upload_cutoff_enabled,true',
        ]);

        $entityId = app(EntityContext::class)->id();
        if (! $entityId) {
            return back()->withErrors(['error' => 'No active entity selected. Please select an entity before saving settings.']);
        }

        MonthEndCountSetting::updateOrCreate(
            ['entity_id' => $entityId],
            $validated
        );

        return redirect()->route('month-end-schedules.index')->with('success', 'Month End Count configuration saved successfully.');
    }

    public function store(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2000|max:2099',
        ]);

        $year = $request->year;

        // Check if schedules for this year already exist
        $exists = MonthEndSchedule::where('year', $year)->exists();
        if ($exists) {
            return back()->withErrors(['error' => 'Month end schedules for this year already exist.']);
        }

        $schedulesToCreate = [];
        for ($month = 1; $month <= 12; $month++) {
            $date = Carbon::createFromDate($year, $month, 1)->endOfMonth();

            // Adjust for weekends: if month end is Sat or Sun, move to preceding Friday
            if ($date->isSaturday()) {
                $date->subDays(1); // Move to Friday
            } elseif ($date->isSunday()) {
                $date->subDays(2); // Move to Friday
            }

            $schedulesToCreate[] = [
                'year' => $year,
                'month' => $month,
                'calculated_date' => $date->toDateString(),
                'status' => 'pending',
                'created_by' => Auth::id(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        }

        MonthEndSchedule::insert($schedulesToCreate);

        return redirect()->route('month-end-schedules.index')->with('success', "Month end schedules for {$year} created successfully.");
    }

    /**
     * Adjust a schedule's count date.
     *
     * Editing is deliberately allowed even after stores have submitted, because
     * moving this date is how support reopens a closed upload window (the whole
     * window is derived from it). The schedule is audited, so every reopen is
     * attributable.
     *
     * One guard remains: once counts exist the date must stay in the past. A
     * future date takes the schedule out of the upload query entirely and turns
     * it back into a template *download* — the exact opposite of reopening, and
     * silently so.
     */
    public function update(Request $request, MonthEndSchedule $schedule)
    {
        $request->validate([
            'calculated_date' => 'required|date',
        ]);

        $submittedCount = DB::table('month_end_count_items')->where('month_end_schedule_id', $schedule->id)->count();
        $newDate = Carbon::parse($request->calculated_date, 'Asia/Manila')->startOfDay();

        if ($submittedCount > 0 && $newDate->gte(Carbon::today('Asia/Manila'))) {
            return back()->withErrors([
                'error' => 'This count already has submissions, so its date must stay in the past. '
                    .'A future date would hide the upload option instead of reopening it.',
            ]);
        }

        $schedule->update([
            'calculated_date' => $request->calculated_date,
        ]);

        // Tell the user what the change actually did to the upload window —
        // otherwise a reopen looks identical to a no-op until someone checks.
        $settings = $this->settingsService->current();
        $opens = $this->settingsService->uploadStart($newDate, $settings);
        $closes = $this->settingsService->uploadCutoff($newDate, $settings);
        $isOpen = $this->settingsService->isUploadOpen(Carbon::now('Asia/Manila'), $newDate, $settings);

        $window = $isOpen
            ? 'Uploading is now OPEN for this count'.($closes ? ' until '.$closes->format('M j, Y g:i A').'.' : '.')
            : 'Uploading is still closed for this count (window: '.$opens->format('M j, Y')
                .($closes ? ' to '.$closes->format('M j, Y g:i A') : ' onwards').').';

        return redirect()->route('month-end-schedules.index')
            ->with('success', 'Schedule updated successfully. '.$window);
    }

    /**
     * Reopen a closed upload window for specific stores on one schedule.
     *
     * Preferred over moving the schedule's count date: it targets only the
     * stores named, leaves the count date of record alone, and expires by
     * itself. Stores that already submitted are rejected — they have nothing
     * to upload, and reopening them would let a second count in.
     */
    public function reopen(Request $request, MonthEndSchedule $schedule)
    {
        $validated = $request->validate([
            'branch_ids' => 'required|array|min:1',
            'branch_ids.*' => 'integer|exists:store_branches,id',
            'reopened_until' => 'required|date',
        ]);

        $until = Carbon::parse($validated['reopened_until'], 'Asia/Manila');

        if ($until->lte(Carbon::now('Asia/Manila'))) {
            return back()->withErrors(['error' => 'The reopen deadline must be in the future.']);
        }

        $alreadySubmitted = DB::table('month_end_count_items')
            ->where('month_end_schedule_id', $schedule->id)
            ->whereIn('branch_id', $validated['branch_ids'])
            ->whereNotIn('status', ['rejected'])
            ->distinct()
            ->pluck('branch_id');

        $targets = collect($validated['branch_ids'])->diff($alreadySubmitted);

        if ($targets->isEmpty()) {
            return back()->withErrors([
                'error' => 'Those stores have already submitted for this count, so there is nothing to reopen.',
            ]);
        }

        foreach ($targets as $branchId) {
            MonthEndCountReopen::updateOrCreate(
                ['month_end_schedule_id' => $schedule->id, 'branch_id' => $branchId],
                [
                    'entity_id' => $schedule->entity_id,
                    'reopened_until' => $until->format('Y-m-d H:i:s'),
                    'reopened_by' => Auth::id(),
                ]
            );
        }

        $skipped = count($validated['branch_ids']) - $targets->count();

        return back()->with('success', sprintf(
            'Upload reopened for %d store%s until %s.%s',
            $targets->count(),
            $targets->count() === 1 ? '' : 's',
            $until->format('M j, Y g:i A'),
            $skipped > 0 ? " {$skipped} already submitted and were skipped." : ''
        ));
    }

    public function destroy(MonthEndSchedule $schedule)
    {
        $submittedCount = DB::table('month_end_count_items')->where('month_end_schedule_id', $schedule->id)->count();
        if ($submittedCount > 0) {
            return back()->withErrors(['error' => 'Cannot delete schedule once a store has started the count.']);
        }

        // Optional: Add policy/gate to check if user can delete
        $schedule->delete();

        return redirect()->route('month-end-schedules.index')->with('success', 'Schedule deleted successfully.');
    }

    public function getDetails(Request $request, MonthEndSchedule $schedule)
    {
        $search = $request->input('search');

        $storesQuery = StoreBranch::where('is_active', true);

        if ($search) {
            $storesQuery->where('name', 'like', '%'.$search.'%');
        }

        $paginatedStores = $storesQuery->orderBy('name')->paginate(10, ['id', 'name']);

        $storeIdsOnPage = $paginatedStores->pluck('id');

        $progress = DB::table('month_end_count_items')
            ->where('month_end_schedule_id', $schedule->id)
            ->whereIn('branch_id', $storeIdsOnPage)
            ->select('branch_id', 'status')
            ->distinct()
            ->get()
            ->keyBy('branch_id');

        // Standing reopens, so the modal can show which stores were already
        // granted an extension and until when.
        $reopens = MonthEndCountReopen::where('month_end_schedule_id', $schedule->id)
            ->whereIn('branch_id', $storeIdsOnPage)
            ->get()
            ->keyBy('branch_id');

        $now = Carbon::now('Asia/Manila');

        $paginatedStores->getCollection()->transform(function ($store) use ($progress, $reopens, $now) {
            $status = $progress->get($store->id);
            $store->status = $status ? str_replace('_', ' ', Str::title($status->status)) : 'Not Started';

            // A store that already submitted has nothing to reopen.
            $store->has_submitted = $status !== null;
            $store->can_reopen = ! $store->has_submitted;

            $reopen = $reopens->get($store->id);
            $reopenUntil = $reopen
                ? Carbon::parse($reopen->reopened_until->format('Y-m-d H:i:s'), 'Asia/Manila')
                : null;

            $store->reopened_until = $reopenUntil?->format('M j, Y g:i A');
            $store->reopen_active = $reopenUntil ? $reopenUntil->gte($now) : false;

            return $store;
        });

        return response()->json($paginatedStores);
    }
}
