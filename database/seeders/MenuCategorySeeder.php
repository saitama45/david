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

        // Create menus
        $menus = [
            [
                'category_id' => 1,
                'name' => 'Waffle with Ice Cream',
                'price' => 200
            ],
            [
                'category_id' => 2,
                'name' => 'Pesto Cream with Grilled Chicken (Solo)',
                'price' => 456
            ],
            [
                'category_id' => 3,
                'name' => 'Nono\'s Sisig',
                'price' => 399
            ],
        ];

        foreach ($menus as $menu) {
            Menu::updateOrCreate($menu);
        }


        $menuIngredients = [

            [
                'menu_id' => 1,
                'inventory_codes' => ['250D9A', '359A2A', '101D9A'],
                'quantities' => [1, 1, 1]
            ],

            [
                'menu_id' => 2,
                'inventory_codes' => ['269D9A', '874A2C', '392A2A', '105A2A'],
                'quantities' => [1, 1, 0.3, 1]
            ],
            [
                'menu_id' => 3,
                'inventory_codes' => ['399A2A', '453A2A', 'FR040100137', 'FR040100082'],
                'quantities' => [1, 1, 0.2, 0.1]
            ]
        ];

        foreach ($menuIngredients as $menuIngredient) {
            foreach ($menuIngredient['inventory_codes'] as $index => $code) {
                $productInventoryId = ProductInventory::where('inventory_code', $code)->first()->id;

                MenuIngredient::updateOrCreate([
                    'menu_id' => $menuIngredient['menu_id'],
                    'product_inventory_id' => $productInventoryId,
                    'quantity' => $menuIngredient['quantities'][$index]
                ]);
            }
        }
    }
}
