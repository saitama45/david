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
        $role2 = Role::create(['name' => 'store representative']);
        $role3 = Role::create(['name' => 'request approver']);


        // User
        Permission::create(['name' => 'create users']);
        Permission::create(['name' => 'view users']);
        Permission::create(['name' => 'edit users']);
        Permission::create(['name' => 'view user']);
        Permission::create(['name' => 'delete user']);

        // Roles
        Permission::create(['name' => 'view roles']);
        Permission::create(['name' => 'create roles']);
        Permission::create(['name' => 'edit roles']);
        Permission::create(['name' => 'delete roles']);

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

        // Orders Approval
        Permission::create(['name' => 'view orders for cs approval list']);
        Permission::create(['name' => 'view order for cs approval']);
        Permission::create(['name' => 'cs approve/decline order request']);

        // Approved Orders
        Permission::create(['name' => 'view approved orders']);
        Permission::create(['name' => 'view approved order']);
        Permission::create(['name' => 'receive orders']);

        // Approvals
        Permission::create(['name' => 'view received orders for approval list']);
        Permission::create(['name' => 'view approved order for approval']);
        Permission::create(['name' => 'approve received orders']);
        Permission::create(['name' => 'approve image attachments']);

        // Approved Received Items
        Permission::create(['name' => 'view approved received items']);
        Permission::create(['name' => 'view approved received item']);
        Permission::create(['name' => 'cancel approved received item']);

        // Store Transactions
        Permission::create(['name' => 'view store transactions']);
        Permission::create(['name' => 'create store transactions']);
        Permission::create(['name' => 'view store transaction']);
        Permission::create(['name' => 'edit store transactions']);

        // Items
        Permission::create(['name' => 'view items list']);
        Permission::create(['name' => 'create new items']);
        Permission::create(['name' => 'edit items']);
        Permission::create(['name' => 'view item']);
        Permission::create(['name' => 'delete items']);

        // BOM
        Permission::create(['name' => 'view bom list']);
        Permission::create(['name' => 'view bom']);
        Permission::create(['name' => 'create bom']);
        Permission::create(['name' => 'edit bom']);

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

        // Manage References
        Permission::create(['name' => 'manage references']);

        $role->syncPermissions(Permission::all());

        $role2->syncPermissions([
            'view store orders',
            'create store orders',
            'edit store orders',
            'view store order',
            'view dts orders',
            'edit dts orders',
            'view dts order',
            'create dts orders',
            'view approved orders',
            'view approved order',
            'receive orders',
            'view stock management',
            'log stock usage',
            'add stock quantity',
            'view stock management history',
            'view items order summary',
        ]);

        $role3->syncPermissions([
            'view orders for approval list',
            'view order for approval',
            'approve/decline order request',
            'view approved received items',
            'view approved received item',
            'view received orders for approval list',
            'view approved order for approval',
            'approve image attachments'
        ]);
    }
}
