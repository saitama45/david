<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class DeliveryScheduleController extends Controller
{
    public function index()
    {
        $branches = StoreBranch::with('delivery_schedules')->paginate(10);

        $formattedResult = $branches->through(function ($branch) {
            $result = [
                'id' => $branch->id,
                'name' => $branch->name,
                'branch_code' => $branch->branch_code,
            ];

            $groupedSchedules = $branch->delivery_schedules
                ->groupBy('pivot.variant')
                ->map(function ($schedules) {
                    return $schedules->pluck('day')->toArray();
                });

            foreach ($groupedSchedules as $variant => $days) {
                $result[Str::snake(strtolower($variant))] = ['day' => $days];
            }

            return $result;
        });


        return Inertia::render('DTSDeliverySchedule/Index', [
            'branches' => $formattedResult
        ]);
    }

    public function edit($id)
    {
        return Inertia::render('DTSDeliverySchedule/Edit');
    }
}
