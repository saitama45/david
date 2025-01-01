<?php

namespace App\Http\Controllers;

use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;

class DeliveryScheduleController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreBranch::query()->with('delivery_schedules');

        if ($search)
            $query->where('name', 'like', "%$search%");

        $branches = $query->paginate(10);

        $formattedResult = $branches->through(function ($branch) {
            $result = [
                'id' => $branch->id,
                'name' => $branch->name,
                'location_code' => $branch->location_code,
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
            'branches' => $formattedResult,
            'filters' => request()->only(['search'])
        ]);
    }

    public function edit($id)
    {
        $branch = StoreBranch::query()->with('delivery_schedules')->findOrFail($id);
        $groupedSchedules = $branch->delivery_schedules
            ->groupBy('pivot.variant')
            ->map(function ($schedules) {
                return $schedules->pluck('id')->toArray();
            })
            ->mapWithKeys(function ($value, $key) {
                $snakeKey = Str::snake(strtolower($key));
                return [$snakeKey => $value];
            });


        $deliverySchedules = DeliverySchedule::options()->map(function ($day, $id) {
            return ['value' => $id, 'label' => $day];
        });


        return Inertia::render('DTSDeliverySchedule/Edit', [
            'branch' => $branch,
            'schedules' => $groupedSchedules,
            'deliverySchedules' => $deliverySchedules
        ]);
    }

    public function update(Request $request, $id)
    {
        $branch = StoreBranch::findOrFail($id);

        $request->validate([
            'ice_cream' => 'array',
            'salmon' => 'array',
            'fruits_and_vegetables' => 'array'
        ]);

        $branch->delivery_schedules()->detach();

        $attachSchedules = function ($schedules, $variant) use ($branch) {
            if (!empty($schedules)) {
                foreach ($schedules as $scheduleId) {
                    $branch->delivery_schedules()->attach($scheduleId, [
                        'variant' => strtoupper(str_replace('_', ' ', $variant))
                    ]);
                }
            }
        };

        $attachSchedules($request->ice_cream, 'ice_cream');
        $attachSchedules($request->salmon, 'salmon');
        $attachSchedules($request->fruits_and_vegetables, 'fruits_and_vegetables');

        return redirect()->route('delivery-schedules.index');
    }
}
