<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'BEVERAGE',
            'CLEANING SUPPLIES',
            'EQUIPMENT',
            'FOOD',
            'DRY GOODS',
            'EQUIPMENT',
            'FINISHED GOOD',
            'FOOD',
            'IT ITEMS',
            'MACHINE',
            'MKTG',
            'RETAIL',
            'SMALLWARES',
            'WORK IN PROCESS',
            'ICE CREAM',
            'SALMON',
        ];

        foreach ($categories as $category) {
            ProductCategory::create([
                'name' => $category
            ]);
        }
    }
}
