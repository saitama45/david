<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class InventoryCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'GSI',
            'FOOD BUNDLE',
            'RND',
            'BAKERY',
            'PROCCESSED FOOD - In stork work in process',
            'FRUITS AND VEGETABLES',
            'FROZEN',
            'DRINKS',
            'RETAIL',
            'TBK',
            'NHI',
            'MACHINE',
            'OPERATING SUPPLIES / OFFICE SUPPLIES / SMALLWARES / MARKETING'
        ];

        foreach ($categories as $category) {
            InventoryCategory::create([
                'name' => $category
            ]);
        }
    }
}
