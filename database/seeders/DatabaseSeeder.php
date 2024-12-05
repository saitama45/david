<?php

namespace Database\Seeders;

use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'admin@gmail.com',
            'password' => 'admin1234',
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'soencoder@gmail.com',
            'password' => 'so1234',
            'role' => 'so_encoder'
        ]);

        $this->call([
            UnitOfMeasurementSeeder::class,
            ProductCategorySeeder::class,
            InventoryCategorySeeder::class,
            StoreBranchSeeder::class,
            SupplierSeeder::class
        ]);
    }
}
