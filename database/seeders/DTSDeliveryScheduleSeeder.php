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
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 1
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 3
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 5
            ],
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 2
            ],
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 5
            ],
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 3
            ],
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 6
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 1
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 4
            ],
        ];

        foreach ($schedules as $schedule) {
            DTSDeliverySchedule::create($schedule);
        }
    }
}
