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
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            // FIL
            [
                'store_branch_id' => 8,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
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
            //RAO - ANTIPOLO
            [
                'store_branch_id' => 20,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 20,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            //SFW - SJDM - 24 (use SFV as per N.A) No mapping yet in Store Branch
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            //SGC - CAMANAVA
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 1,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],
            //3CN - MAKATI
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            //RWL - MAKATI
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            //GLO - MAKATI
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            //KLT - MAKATI
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            //HSS - BGC
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            //UPM - BGC
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 5,
                'variant' => 'ICE CREAM'
            ],
            //BIC - SM BICUTAN
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 2,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //NUV - NUVALI
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
            //SSR - STA ROSA
            [
                'store_branch_id' => 26,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 26,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //VIA - CAVITE
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //PDM - ORTIGAS
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //UTC - QC
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //GWM - QC
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //TOL - NORTH
            [
                'store_branch_id' => 28,
                'delivery_schedule_id' => 3,
                'variant' => 'ICE CREAM'
            ],
            [
                'store_branch_id' => 28,
                'delivery_schedule_id' => 6,
                'variant' => 'ICE CREAM'
            ],
            //TOL - NORTH
            [
                'store_branch_id' => 14,
                'delivery_schedule_id' => 4,
                'variant' => 'ICE CREAM'
            ],

            //End of Ice Cream


            // Salmon

            // Rockwell
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

            // SM Maison
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

            // SM Bicutan
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 23,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // Laus Group Complex
            [
                'store_branch_id' => 14,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            // The Outlets at Lipa
            [
                'store_branch_id' => 28,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            // Uptown Mall
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // Podium Ortigas 
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // Glorietta 2 
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // Three Central
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // High Street South 
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // KL Tower Serviced Residences
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 13,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // Robinson's Antipolo
            [
                'store_branch_id' => 20,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 20,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 20,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // Robinson's Antipolo
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 6,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // Greenfield
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 1,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 3,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 5,
                'variant' => 'SALMON'
            ],
            // NONO'S UP TOWN Center
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 16,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // NONO'S UP TOWN Center
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 7,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // Filinvest Super Mall
            [
                'store_branch_id' => 8,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 8,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 8,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // Nuvali
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 17,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // Nono's Okada
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // SM Santa Rosa
            [
                'store_branch_id' => 26,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 26,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 26,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // SM Fairview Nono's
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // SM Grand Central
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // Gateway Mall 2
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 6,
                'variant' => 'SALMON'
            ],
            // Vermosa
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 2,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 4,
                'variant' => 'SALMON'
            ],
            [
                'store_branch_id' => 31,
                'delivery_schedule_id' => 6,
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

            // Metro Manila

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
            // NNOKA - 15
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 15,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // PDM - 19
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 19,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // GFD - 11
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 11,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // RWL - 21
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 21,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // GLO - 10
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 10,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // SFW - 24
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 3,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 24,
                'delivery_schedule_id' => 5,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // ABA - 7
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
            // HSS - 12
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 12,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // SMA - 22
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
             // GWM - 9
             [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 9,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
             // UPM - 30
             [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 30,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // SGC - 25
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 25,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            // RAO - 22
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 2,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 22,
                'delivery_schedule_id' => 6,
                'variant' => 'FRUITS AND VEGETABLES'
            ],            
            // 3CN - 29
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 1,
                'variant' => 'FRUITS AND VEGETABLES'
            ],
            [
                'store_branch_id' => 29,
                'delivery_schedule_id' => 4,
                'variant' => 'FRUITS AND VEGETABLES'
            ],        
        ];

        foreach ($schedules as $schedule) {
            DTSDeliverySchedule::updateOrCreate($schedule);
        }
    }
}
