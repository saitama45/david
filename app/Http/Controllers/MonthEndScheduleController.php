<?php

namespace App\Http\Controllers;

use App\Models\MonthEndSchedule;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MonthEndScheduleController extends Controller
{
    public function index(Request $request)
    {
        $selectedYear = $request->input('year');
        if (!$selectedYear) {
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
            $schedule->progress = $progressData->get($schedule->id, collect())->keyBy('status')->map(fn($item) => (int)$item->count);
            return $schedule;
        });

        return Inertia::render('MonthEndSchedule/Index', [
            'schedules' => $schedules,
            'filters' => ['year' => $selectedYear],
            'can' => [
                'create_month_end_schedules' => Auth::user()->can('create month end schedules'),
                'edit_month_end_schedules' => Auth::user()->can('edit month end schedules'),
                'delete_month_end_schedules' => Auth::user()->can('delete month end schedules'),
            ]
        ]);
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

    public function update(Request $request, MonthEndSchedule $schedule)
    {
        $submittedCount = DB::table('month_end_count_items')->where('month_end_schedule_id', $schedule->id)->count();
        if ($submittedCount > 0) {
            return back()->withErrors(['error' => 'Cannot update schedule once a store has started the count.']);
        }

        $request->validate([
            'calculated_date' => 'required|date',
        ]);

        $schedule->update([
            'calculated_date' => $request->calculated_date,
        ]);

        return redirect()->route('month-end-schedules.index')->with('success', 'Schedule updated successfully.');
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
            $storesQuery->where('name', 'like', '%' . $search . '%');
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

        $paginatedStores->getCollection()->transform(function ($store) use ($progress) {
            $status = $progress->get($store->id);
            $store->status = $status ? str_replace('_', ' ', Str::title($status->status)) : 'Not Started';
            return $store;
        });

        return response()->json($paginatedStores);
    }
}
