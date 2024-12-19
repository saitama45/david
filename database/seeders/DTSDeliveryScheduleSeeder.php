<?php

namespace Database\Seeders;

use App\Models\DTSDeliverySchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DTSDeliveryScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schedules = [
            // Ice Cream
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            // Salmon
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
        ];

        foreach ($schedules as $schedule) {
            DTSDeliverySchedule::create($schedule);
        }
    }
}
