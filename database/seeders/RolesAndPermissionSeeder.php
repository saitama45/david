<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'so encoder']);
        Role::create(['name' => 'rec encoder']);
        Role::create(['name' => 'rec approver']);

        Permission::create(['name' => 'admin access']);
        Permission::create(['name' => 'create so']);
        Permission::create(['name' => 'edit so']);
        Permission::create(['name' => 'view so per store']);
        Permission::create(['name' => 'view-so all stores']);
        Permission::create(['name' => 'upload receive so']);
        Permission::create(['name' => 'edi received so']);
        Permission::create(['name' => 'approve receive so']);
        Permission::create(['name' => 'view approved received so per store']);
        Permission::create(['name' => 'view approved received so all store']);
    }
}
