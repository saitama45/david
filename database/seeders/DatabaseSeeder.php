<?php

namespace Database\Seeders;

use App\Models\DeliverySchedule;
use App\Models\DTSDeliverySchedule;
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

        $this->call([
            UnitOfMeasurementSeeder::class,
            ProductCategorySeeder::class,
            InventoryCategorySeeder::class,
            StoreBranchSeeder::class,
            SupplierSeeder::class,
            DeliveryScheduleSeeder::class,
            DTSDeliveryScheduleSeeder::class,
            MenuCategorySeeder::class
        ]);
    }
}
