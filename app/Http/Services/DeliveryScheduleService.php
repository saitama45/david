<?php

namespace App\Http\Services;

use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryScheduleService
{
    public function updateDeliveryScedule(array $data, $id)
    {
        DB::beginTransaction();
        $branch = StoreBranch::findOrFail($id);
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

        $attachSchedules($data['ice_cream'], 'ice_cream');
        $attachSchedules($data['salmon'], 'salmon');
        $attachSchedules($data['fruits_and_vegetables'], 'fruits_and_vegetables');

        DB::commit();
    }

    public function getSchedules($id)
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

        return [
            'branch' => $branch,
            'groupedSchedules' => $groupedSchedules,
            'deliverySchedules' => $deliverySchedules
        ];
    }

    public function getBranchList()
    {
        $search = request('search');
        $query = StoreBranch::query()->with('delivery_schedules');

        if ($search)
            $query->where('name', 'like', "%$search%");

        $branches = $query->latest()->paginate(10);

        return $branches->through(function ($branch) {
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
    }
}
