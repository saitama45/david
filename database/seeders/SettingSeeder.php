<?php

namespace Database\Seeders;

use App\Http\Services\WastageApprovalSettingsService;
use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::firstOrCreate(
            ['key' => WastageApprovalSettingsService::REQUIRED_LEVELS_KEY],
            [
                'value' => '2',
                'type' => 'integer',
            ]
        );
    }
}
