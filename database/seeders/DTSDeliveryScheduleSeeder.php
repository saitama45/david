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
            // 1 - Monday
            // 2 - Tuesay
            // 3 - Wednesday
            // 4 - Thursday 
            // 5- Friday 
            // 6 -Saturday 

            // Ice Cream

            // GREENFIELD
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
            // AYALA MALLS MANILA BAY
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            // OKADA
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            // FIL
            [
                'store_branch_id' => 8,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            // Vermosa 
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
            // Nuvali
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
            // SM MAISON
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


            // Fruits and veggies 
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
        ];

        foreach ($schedules as $schedule) {
            DTSDeliverySchedule::create($schedule);
        }
    }
}
