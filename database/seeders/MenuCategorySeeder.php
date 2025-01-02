<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuIngredient;
use App\Models\ProductInventory;
use Illuminate\Database\Seeder;

class MenuCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create categories
        $categories = ['DESSERTS', 'PASTAS', 'MAINS'];
        foreach ($categories as $category) {
            MenuCategory::updateOrCreate([
                'name' => $category
            ]);
        }

        
    }
}
