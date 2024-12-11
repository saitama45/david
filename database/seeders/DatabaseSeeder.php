<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionSeeder::class);
        $admin = User::factory()->create([
            'email' => 'admin@gmail.com',
            'password' => Hash::make('admin1234')
        ]);

        $admin->assignRole(['admin']);
        $admin->givePermissionTo(Permission::all());

        $user = User::factory()->create([
            'email' => 'soencoder@gmail.com',
            'password' => Hash::make('admin1234')
        ]);

        $user->assignRole(['so encoder']);

        $user = User::factory()->create([
            'email' => 'recencoder@gmail.com',
            'password' => Hash::make('admin1234')
        ]);

        $user->assignRole(['rec encoder']);

        $user = User::factory()->create([
            'email' => 'recapprover@gmail.com',
            'password' => Hash::make('admin1234')
        ]);

        $user->assignRole(['rec approver']);



        $this->call([
            UnitOfMeasurementSeeder::class,
            ProductCategorySeeder::class,
            InventoryCategorySeeder::class,
            StoreBranchSeeder::class,
            SupplierSeeder::class
        ]);
    }
}
