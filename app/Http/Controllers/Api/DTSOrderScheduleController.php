<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreBranch;
use Illuminate\Http\Request;

class DTSOrderScheduleController extends Controller
{
    public function show($id, Request $request)
    {
        $validated = $request->validate([
            'variant' => 'required'
        ]);
        $schedules = StoreBranch::with(['delivery_schedules' => function ($query) use ($validated) {
            $query->select('day')
                ->wherePivot('variant', strtoupper($validated['variant']));
        }])->find($id);

        $days = $schedules->delivery_schedules->pluck('day')
            ->unique()
            ->values()
            ->toArray();

// [1, 3, 5]
        return response()->json($days);
    }
}
