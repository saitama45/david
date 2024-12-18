<?php

namespace Database\Seeders;

use App\Models\DeliverySchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DeliveryScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $daysOfTheWeek = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

        foreach ($daysOfTheWeek as $day) {
            DeliverySchedule::create([
                'day' => $day
            ]);
        }
    }
}
