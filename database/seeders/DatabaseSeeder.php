<?php

namespace Database\Seeders;

use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
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

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'admin@gmail.com',
        ]);

        UserRole::create([
            'user_id' => 1,
            'role' => 'admin'
        ]);

        $user1 = User::factory()->create([
            'name' => 'Test User',
            'email' => 'soencoder@gmail.com',
            'password' => 'so1234',
        ]);

        UserRole::create([
            'user_id' => 2,
            'role' => 'so_encoder',
        ]);

        
        UserRole::create([
            'user_id' => 2,
            'role' => 'rec_approver'
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
