<?php

namespace App\Http\Controllers;

use App\Models\DTSDeliverySchedule;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DTSMassOrdersController extends Controller
{
    public function index(Request $request)
    {
        $allowedVariants = ['ICE CREAM', 'SALMON', 'FRUITS AND VEGETABLES'];

        $variants = DTSDeliverySchedule::whereIn('variant', $allowedVariants)
            ->distinct()
            ->pluck('variant')
            ->map(function ($variant) {
                return [
                    'label' => $variant,
                    'value' => $variant
                ];
            })
            ->values();

        return Inertia::render('DTSMassOrders/Index', [
            'variants' => $variants
        ]);
    }

    public function create(Request $request)
    {
        $variant = $request->input('variant');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        return Inertia::render('DTSMassOrders/Create', [
            'variant' => $variant,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    }

    public function store(Request $request)
    {
        // TODO: Implement store logic
    }

    public function show($id)
    {
        return Inertia::render('DTSMassOrders/Show');
    }

    public function edit($id)
    {
        return Inertia::render('DTSMassOrders/Edit');
    }

    public function update(Request $request, $id)
    {
        // TODO: Implement update logic
    }

    public function export(Request $request)
    {
        // TODO: Implement export logic
    }

    public function getAvailableDates($variant)
    {
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $variant)->first();
        if (!$cutoff) {
            return response()->json([]);
        }

        $now = \Carbon\Carbon::now('Asia/Manila');

        $getCutoffDate = function($day, $time) use ($now) {
            if (!$day || !$time) return null;
            $dayIndex = ($day == 7) ? 0 : $day;
            return $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
        };

        $cutoff1Date = $getCutoffDate($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
        $cutoff2Date = $getCutoffDate($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

        $daysToCoverStr = '';
        $weekOffset = 0;

        // Determine which set of days and which week to use
        if ($cutoff1Date && $now->lt($cutoff1Date)) {
            $daysToCoverStr = $cutoff->days_covered_1;
            $weekOffset = 0; // Current week (next available days this week)
        } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
            $daysToCoverStr = $cutoff->days_covered_2;
            $weekOffset = 0; // Current week (next available days this week)
        } else {
            // After all cutoffs, next week
            $daysToCoverStr = $cutoff->days_covered_1;
            $weekOffset = 1; // Next week
        }

        $startOfTargetWeek = $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addWeeks($weekOffset);

        $daysToCover = $daysToCoverStr ? explode(',', $daysToCoverStr) : [];
        $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        $enabledDates = [];
        foreach ($daysToCover as $day) {
            $day = trim($day);
            if (isset($dayMap[$day])) {
                $date = $startOfTargetWeek->copy()->addDays($dayMap[$day]);
                $enabledDates[] = $date->toDateString();
            }
        }

        return response()->json($enabledDates);
    }
}
