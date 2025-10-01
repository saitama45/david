<?php

namespace App\Http\Controllers;

use App\Models\MonthEndSchedule;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class MonthEndScheduleController extends Controller
{
    public function index()
    {
        $schedules = MonthEndSchedule::with(['creator:id,first_name,last_name'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate(15);

        // Branches are no longer needed for schedule creation, but might be for other parts of the UI.
        // For now, we'll remove it as it's not directly related to schedule creation anymore.
        // $branches = StoreBranch::where('is_active', true)->pluck('name', 'id');

        return Inertia::render('MonthEndSchedule/Index', [
            'schedules' => $schedules,
            // 'branches' => $branches,
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

    public function destroy(MonthEndSchedule $schedule)
    {
        // Optional: Add policy/gate to check if user can delete
        $schedule->delete();

        return redirect()->route('month-end-schedules.index')->with('success', 'Schedule deleted successfully.');
    }
}
