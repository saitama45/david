<?php

namespace App\Http\Controllers;

use App\Models\OrdersCutoff;
use App\Models\Supplier;
use App\Models\SupplierItems;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MassOrdersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $suppliers = Auth::user()->suppliers()
            ->where('is_active', true)
            ->get()
            ->map(function ($supplier) {
                return [
                    'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                    'value' => $supplier->supplier_code,
                ];
            });

        return Inertia::render('MassOrders/Index', [
            'massOrders' => [],
            'suppliers' => $suppliers,
            'ordersCutoff' => OrdersCutoff::all(),
            'currentDate' => Carbon::now()->toDateString(), // Pass current server date
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function getAvailableDates($supplier_code)
    {
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $supplier_code)->first();
        if (!$cutoff) {
            return response()->json([]);
        }

        $now = Carbon::now();

        $getCutoffDate = function($day, $time) use ($now) {
            if (!$day || !$time) return null;
            $dayIndex = ($day == 7) ? 0 : $day;
            return $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
        };

        $cutoff1Date = $getCutoffDate($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
        $cutoff2Date = $getCutoffDate($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

        $daysToCoverStr = '';
        $weekOffset = 0; // How many weeks to add to the current week's start

        // Determine which set of days and which week to use
        if ($cutoff1Date && $now->lt($cutoff1Date)) {
            $daysToCoverStr = $cutoff->days_covered_1;
            // If it's a GSI supplier, the delivery is always next week.
            $weekOffset = str_starts_with($supplier_code, 'GSI') ? 1 : 0;
        } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
            $daysToCoverStr = $cutoff->days_covered_2;
            // If it's a GSI supplier, the delivery is always next week.
            $weekOffset = str_starts_with($supplier_code, 'GSI') ? 1 : 0;
        } else {
            // After all cutoffs, it's always next week for everyone.
            $daysToCoverStr = $cutoff->days_covered_1;
            $weekOffset = 1;
        }

        $startOfTargetWeek = $now->copy()->startOfWeek(Carbon::SUNDAY)->addWeeks($weekOffset);

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

    public function getItems($supplier_code)
    {
        $items = \App\Models\SupplierItems::where('supplier_code', $supplier_code)->get();
        return response()->json($items);
    }
}