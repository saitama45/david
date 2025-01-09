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
        $role = Role::create(['name' => 'admin']);

        // Roles
        Permission::create(['name' => 'view roles']);
        Permission::create(['name' => 'create role']);
        Permission::create(['name' => 'edit role']);

        // DTS Delivery Schedules
        Permission::create(['name' => 'view dts delivery schedules']);
        Permission::create(['name' => 'edit dts delivery schedules']);

        // Store Orders
        Permission::create(['name' => 'view store orders']);
        Permission::create(['name' => 'edit store orders']);
        Permission::create(['name' => 'create store orders']);
        Permission::create(['name' => 'view store order']);

        // DTS Orders
        Permission::create(['name' => 'view dts orders']);
        Permission::create(['name' => 'edit dts orders']);
        Permission::create(['name' => 'create dts orders']);
        Permission::create(['name' => 'view dts order']);

        // Orders Approval
        Permission::create(['name' => 'view orders for approval list']);
        Permission::create(['name' => 'view order for approval']);
        Permission::create(['name' => 'approve/decline order request']);
        Permission::create(['name' => 'edit orders for approval']);

        // Approved Orders
        Permission::create(['name' => 'view approved orders']);
        Permission::create(['name' => 'view approved order']);
        Permission::create(['name' => 'receive order']);

        // Approvals
        Permission::create(['name' => 'view received orders for approval list']);
        Permission::create(['name' => 'view approved order for approval']);
        Permission::create(['name' => 'approve received orders']);
        Permission::create(['name' => 'approve image attachments']);

        // Approved Received Items
        Permission::create(['name' => 'view received orders for approval list']);
        Permission::create(['name' => 'view received order for approval']);
        Permission::create(['name' => 'approve received items']);

        // Store Transactions
        Permission::create(['name' => 'view store transactions']);
        Permission::create(['name' => 'create store transaction']);
        Permission::create(['name' => 'view store transaction']);

        // Items
        Permission::create(['name' => 'view items list']);
        Permission::create(['name' => 'create new item']);
        Permission::create(['name' => 'edit item']);
        Permission::create(['name' => 'view item']);

        // Menu
        Permission::create(['name' => 'view menu list']);
        Permission::create(['name' => 'view menu']);
        Permission::create(['name' => 'create menu']);
        Permission::create(['name' => 'edit menu']);

        // Stock Management
        Permission::create(['name' => 'view stock management']);
        Permission::create(['name' => 'log stock usage']);
        Permission::create(['name' => 'add stock quantity']);
        Permission::create(['name' => 'view stock management history']);

        // Items Order Summary
        Permission::create(['name' => 'view items order summary']);
        Permission::create(['name' => 'view ice cream orders']);
        Permission::create(['name' => 'view salmon orders']);
        Permission::create(['name' => 'view fruits and vegetables orders']);

        // User
        Permission::create(['name' => 'create user']);
        Permission::create(['name' => 'view user']);
        Permission::create(['name' => 'edit user']);

        // Manage References
        Permission::create(['name' => 'manage references']);

        $role->syncPermissions(Permission::all());
    }
}
