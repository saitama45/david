<?php

namespace App\Http\Services;

use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryScheduleService
{
    /** The schedules this page owns, form field => variant label. */
    private const VARIANTS = [
        'ice_cream' => 'ICE CREAM',
        'salmon' => 'SALMON',
        'fruits_and_vegetables' => 'FRUITS AND VEGETABLES',
    ];

    /**
     * Replaces the store's DTS schedules only. The same table holds each ordering template's
     * days (Delivery Schedules, variant = supplier code), which must survive this save, or the
     * store drops out of those Mass Orders templates.
     */
    public function updateDeliveryScedule(array $data, $id)
    {
        DB::transaction(function () use ($data, $id) {
            $branch = StoreBranch::findOrFail($id);
            $branch->delivery_schedules()->wherePivotIn('variant', array_values(self::VARIANTS))->detach();

            foreach (self::VARIANTS as $field => $variant) {
                foreach (array_unique($data[$field] ?? []) as $scheduleId) {
                    $branch->delivery_schedules()->attach($scheduleId, ['variant' => $variant]);
                }
            }
        });
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
