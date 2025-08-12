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
        // Clear cache and reset permissions before seeding
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'admin']);
        $role2 = Role::firstOrCreate(['name' => 'store representative']);
        $role3 = Role::firstOrCreate(['name' => 'request approver']);


        // User
        Permission::firstOrCreate(['name' => 'create users']);
        Permission::firstOrCreate(['name' => 'view users']);
        Permission::firstOrCreate(['name' => 'edit users']);
        Permission::firstOrCreate(['name' => 'view user']);
        Permission::firstOrCreate(['name' => 'delete users']);

        // Roles
        Permission::firstOrCreate(['name' => 'view roles']);
        Permission::firstOrCreate(['name' => 'create roles']);
        Permission::firstOrCreate(['name' => 'edit roles']);
        Permission::firstOrCreate(['name' => 'delete roles']);

        // DTS Delivery Schedules
        Permission::firstOrCreate(['name' => 'view dts delivery schedules']);
        Permission::firstOrCreate(['name' => 'edit dts delivery schedules']);
        Permission::firstOrCreate(['name' => 'export dts delivery schedules']); // Added export

        // Store Orders
        Permission::firstOrCreate(['name' => 'view store orders']);
        Permission::firstOrCreate(['name' => 'edit store orders']);
        Permission::firstOrCreate(['name' => 'create store orders']);
        Permission::firstOrCreate(['name' => 'view store order']);
        Permission::firstOrCreate(['name' => 'export store orders']); // Added export

        // Emergency Orders (Added for consistency with desired grouping)
        Permission::firstOrCreate(['name' => 'view emergency orders']);
        Permission::firstOrCreate(['name' => 'create emergency orders']);
        Permission::firstOrCreate(['name' => 'edit emergency orders']);
        Permission::firstOrCreate(['name' => 'delete emergency orders']);
        Permission::firstOrCreate(['name' => 'export emergency orders']);

        // Additional Orders (Added for consistency with desired grouping)
        Permission::firstOrCreate(['name' => 'view additional orders']);
        Permission::firstOrCreate(['name' => 'create additional orders']);
        Permission::firstOrCreate(['name' => 'edit additional orders']);
        Permission::firstOrCreate(['name' => 'delete additional orders']);
        Permission::firstOrCreate(['name' => 'export additional orders']);

        // DTS Orders
        Permission::firstOrCreate(['name' => 'view dts orders']);
        Permission::firstOrCreate(['name' => 'edit dts orders']);
        Permission::firstOrCreate(['name' => 'create dts orders']);
        Permission::firstOrCreate(['name' => 'view dts order']);
        Permission::firstOrCreate(['name' => 'export dts orders']); // Added export

        // Direct Receiving (Added for consistency with desired grouping)
        Permission::firstOrCreate(['name' => 'view direct receiving']);
        Permission::firstOrCreate(['name' => 'create direct receiving']);
        Permission::firstOrCreate(['name' => 'edit direct receiving']);
        Permission::firstOrCreate(['name' => 'delete direct receiving']);
        Permission::firstOrCreate(['name' => 'export direct receiving']);


        // Orders Approval (SM)
        Permission::firstOrCreate(['name' => 'view orders for approval list']);
        Permission::firstOrCreate(['name' => 'view order for approval']);
        Permission::firstOrCreate(['name' => 'approve/decline order request']);

        // CS Review List
        Permission::firstOrCreate(['name' => 'view orders for cs approval list']);
        Permission::firstOrCreate(['name' => 'view order for cs approval']);
        Permission::firstOrCreate(['name' => 'cs approve/decline order request']);
        // NEW: Export permission for CS Approval List
        Permission::firstOrCreate(['name' => 'export orders for cs approval list']);

        // Emergency Order Approval (Added for consistency)
        Permission::firstOrCreate(['name' => 'view emergency order approval']);
        Permission::firstOrCreate(['name' => 'approve emergency order']);
        Permission::firstOrCreate(['name' => 'decline emergency order']);

        // Additional Order Approval (Added for consistency)
        Permission::firstOrCreate(['name' => 'view additional order approval']);
        Permission::firstOrCreate(['name' => 'approve additional order']);
        Permission::firstOrCreate(['name' => 'decline additional order']);


        // Approved Orders
        Permission::firstOrCreate(['name' => 'view approved orders']);
        Permission::firstOrCreate(['name' => 'view approved order']);
        Permission::firstOrCreate(['name' => 'receive orders']);
        Permission::firstOrCreate(['name' => 'export approved orders']); // Added export

        // Receiving Approvals
        Permission::firstOrCreate(['name' => 'view received orders for approval list']);
        Permission::firstOrCreate(['name' => 'view approved order for approval']);
        Permission::firstOrCreate(['name' => 'approve received orders']);
        Permission::firstOrCreate(['name' => 'approve image attachments']);
        Permission::firstOrCreate(['name' => 'export received orders for approval list']); // Added export

        // Approved Received Items
        Permission::firstOrCreate(['name' => 'view approved received items']);
        Permission::firstOrCreate(['name' => 'view approved received item']);
        Permission::firstOrCreate(['name' => 'cancel approved received item']);
        Permission::firstOrCreate(['name' => 'export approved received items']); // Added export

        // Store Transactions
        Permission::firstOrCreate(['name' => 'view store transactions']);
        Permission::firstOrCreate(['name' => 'create store transactions']);
        Permission::firstOrCreate(['name' => 'view store transaction']);
        Permission::firstOrCreate(['name' => 'edit store transactions']);
        Permission::firstOrCreate(['name' => 'export store transactions']); // Added export

        // Store Transactions Approval (Added for consistency)
        Permission::firstOrCreate(['name' => 'view store transactions approval']);
        Permission::firstOrCreate(['name' => 'approve store transactions']);
        Permission::firstOrCreate(['name' => 'decline store transactions']);

        // Items
        Permission::firstOrCreate(['name' => 'view items list']);
        Permission::firstOrCreate(['name' => 'create new items']);
        Permission::firstOrCreate(['name' => 'edit items']);
        Permission::firstOrCreate(['name' => 'view item']);
        Permission::firstOrCreate(['name' => 'delete items']);
        Permission::firstOrCreate(['name' => 'export items list']); // Added export

        // SAP, Supplier, POS Masterfile Items (Added for consistency with sidebar)
        Permission::firstOrCreate(['name' => 'view sapitems list']);
        Permission::firstOrCreate(['name' => 'create sapitems']);
        Permission::firstOrCreate(['name' => 'edit sapitems']);
        Permission::firstOrCreate(['name' => 'delete sapitems']);
        Permission::firstOrCreate(['name' => 'export sapitems list']);

        Permission::firstOrCreate(['name' => 'view SupplierItems list']);
        Permission::firstOrCreate(['name' => 'create SupplierItems']);
        Permission::firstOrCreate(['name' => 'edit SupplierItems']);
        Permission::firstOrCreate(['name' => 'delete SupplierItems']);
        Permission::firstOrCreate(['name' => 'export SupplierItems list']);

        Permission::firstOrCreate(['name' => 'view POSMasterfile list']);
        Permission::firstOrCreate(['name' => 'create POSMasterfile']);
        Permission::firstOrCreate(['name' => 'edit POSMasterfile']);
        Permission::firstOrCreate(['name' => 'delete POSMasterfile']);
        Permission::firstOrCreate(['name' => 'export POSMasterfile list']);

        // NEW: POSMasterfileBOM Permissions
        Permission::firstOrCreate(['name' => 'view POSMasterfile BOM list']);
        Permission::firstOrCreate(['name' => 'view POSMasterfile BOM']);
        Permission::firstOrCreate(['name' => 'create POSMasterfile BOM']);
        Permission::firstOrCreate(['name' => 'edit POSMasterfile BOM']);
        Permission::firstOrCreate(['name' => 'delete POSMasterfile BOM']);
        Permission::firstOrCreate(['name' => 'import POSMasterfile BOM']);
        Permission::firstOrCreate(['name' => 'export POSMasterfile BOM']);


        // BOM (Existing, keeping for context if it refers to something else)
        Permission::firstOrCreate(['name' => 'view bom list']);
        Permission::firstOrCreate(['name' => 'view bom']);
        Permission::firstOrCreate(['name' => 'create bom']);
        Permission::firstOrCreate(['name' => 'edit bom']);
        Permission::firstOrCreate(['name' => 'delete bom']);
        Permission::firstOrCreate(['name' => 'export bom list']); // Added export

        // Stock Management
        Permission::firstOrCreate(['name' => 'view stock management']);
        Permission::firstOrCreate(['name' => 'log stock usage']);
        Permission::firstOrCreate(['name' => 'add stock quantity']);
        Permission::firstOrCreate(['name' => 'view stock management history']);
        Permission::firstOrCreate(['name' => 'export stock management']); // Added export

        // SOH Adjustment (Added for consistency)
        Permission::firstOrCreate(['name' => 'view soh adjustment']);
        Permission::firstOrCreate(['name' => 'create soh adjustment']);
        Permission::firstOrCreate(['name' => 'edit soh adjustment']);
        Permission::firstOrCreate(['name' => 'delete soh adjustment']);
        Permission::firstOrCreate(['name' => 'export soh adjustment']);

        // Low on Stocks
        Permission::firstOrCreate(['name' => 'view low on stocks']);
        Permission::firstOrCreate(['name' => 'export low on stocks']); // Added export

        // Items Order Summary
        Permission::firstOrCreate(['name' => 'view items order summary']);
        Permission::firstOrCreate(['name' => 'view ice cream orders']);
        Permission::firstOrCreate(['name' => 'view salmon orders']);
        Permission::firstOrCreate(['name' => 'view fruits and vegetables orders']);
        Permission::firstOrCreate(['name' => 'export items order summary']); // Added export
        Permission::firstOrCreate(['name' => 'export ice cream orders']);
        Permission::firstOrCreate(['name' => 'export salmon orders']);
        Permission::firstOrCreate(['name' => 'export fruits and vegetables orders']);

        // Templates
        Permission::firstOrCreate(['name' => 'view templates']);
        Permission::firstOrCreate(['name' => 'create templates']);
        Permission::firstOrCreate(['name' => 'edit templates']);
        Permission::firstOrCreate(['name' => 'delete templates']);
        Permission::firstOrCreate(['name' => 'export templates']); // Added export

        // Manage References (General permission, specific ones below)
        Permission::firstOrCreate(['name' => 'manage references']);

        // Reference Sub-categories (Added for consistency with sidebar)
        Permission::firstOrCreate(['name' => 'view category list']);
        Permission::firstOrCreate(['name' => 'create category']);
        Permission::firstOrCreate(['name' => 'edit category']);
        Permission::firstOrCreate(['name' => 'delete category']);
        Permission::firstOrCreate(['name' => 'export category list']);

        Permission::firstOrCreate(['name' => 'view wip list']);
        Permission::firstOrCreate(['name' => 'create wip']);
        Permission::firstOrCreate(['name' => 'edit wip']);
        Permission::firstOrCreate(['name' => 'delete wip']);
        Permission::firstOrCreate(['name' => 'export wip list']);

        Permission::firstOrCreate(['name' => 'view menu categories']);
        Permission::firstOrCreate(['name' => 'create menu category']);
        Permission::firstOrCreate(['name' => 'edit menu category']);
        Permission::firstOrCreate(['name' => 'delete menu category']);
        Permission::firstOrCreate(['name' => 'export menu categories']);

        Permission::firstOrCreate(['name' => 'view uom conversions']);
        Permission::firstOrCreate(['name' => 'create uom conversion']);
        Permission::firstOrCreate(['name' => 'edit uom conversion']);
        Permission::firstOrCreate(['name' => 'delete uom conversion']);
        Permission::firstOrCreate(['name' => 'export uom conversions']);

        Permission::firstOrCreate(['name' => 'view inventory categories']);
        Permission::firstOrCreate(['name' => 'create inventory category']);
        Permission::firstOrCreate(['name' => 'edit inventory category']);
        Permission::firstOrCreate(['name' => 'delete inventory category']);
        Permission::firstOrCreate(['name' => 'export inventory categories']);

        Permission::firstOrCreate(['name' => 'view unit of measurements']);
        Permission::firstOrCreate(['name' => 'create unit of measurement']);
        Permission::firstOrCreate(['name' => 'edit unit of measurement']);
        Permission::firstOrCreate(['name' => 'delete unit of measurement']);
        Permission::firstOrCreate(['name' => 'export unit of measurements']);

        Permission::firstOrCreate(['name' => 'view branches']);
        Permission::firstOrCreate(['name' => 'create branch']);
        Permission::firstOrCreate(['name' => 'edit branch']);
        Permission::firstOrCreate(['name' => 'delete branch']);
        Permission::firstOrCreate(['name' => 'export branches']);

        Permission::firstOrCreate(['name' => 'view suppliers']);
        Permission::firstOrCreate(['name' => 'create supplier']);
        Permission::firstOrCreate(['name' => 'edit supplier']);
        Permission::firstOrCreate(['name' => 'delete supplier']);
        Permission::firstOrCreate(['name' => 'export suppliers']);

        Permission::firstOrCreate(['name' => 'view cost centers']);
        Permission::firstOrCreate(['name' => 'create cost center']);
        Permission::firstOrCreate(['name' => 'edit cost center']);
        Permission::firstOrCreate(['name' => 'delete cost center']);
        Permission::firstOrCreate(['name' => 'export cost centers']);

        // Reports from DashboardController (Added for consistency)
        Permission::firstOrCreate(['name' => 'view top 10 inventories']);
        Permission::firstOrCreate(['name' => 'export top 10 inventories']);
        Permission::firstOrCreate(['name' => 'view days inventory outstanding']);
        Permission::firstOrCreate(['name' => 'export days inventory outstanding']);
        Permission::firstOrCreate(['name' => 'view days payable outstanding']);
        Permission::firstOrCreate(['name' => 'export days payable outstanding']);
        Permission::firstOrCreate(['name' => 'view sales report']);
        Permission::firstOrCreate(['name' => 'export sales report']);
        Permission::firstOrCreate(['name' => 'view inventories report']);
        Permission::firstOrCreate(['name' => 'export inventories report']);
        Permission::firstOrCreate(['name' => 'view upcoming inventories']);
        Permission::firstOrCreate(['name' => 'export upcoming inventories']);
        Permission::firstOrCreate(['name' => 'view account payable']);
        Permission::firstOrCreate(['name' => 'export account payable']);
        Permission::firstOrCreate(['name' => 'view cost of goods']);
        Permission::firstOrCreate(['name' => 'export cost of goods']);


        // Assign all permissions to the 'admin' role
        $role->syncPermissions(Permission::all());

        // Assign specific permissions to 'store representative'
        $role2->syncPermissions([
            'view store orders', 'create store orders', 'edit store orders', 'view store order',
            'view dts orders', 'edit dts orders', 'view dts order', 'create dts orders',
            'view approved orders', 'view approved order', 'receive orders',
            'view stock management', 'log stock usage', 'add stock quantity', 'view stock management history',
            'view items order summary', 'view templates', 'view low on stocks',
            // Added for consistency
            'view emergency orders', 'create emergency orders', 'edit emergency orders',
            'view additional orders', 'create additional orders', 'edit additional orders',
            'view direct receiving', 'create direct receiving', 'edit direct receiving',
            'view sapitems list', 'view SupplierItems list', 'view POSMasterfile list',
            'view bom list', 'view bom', 'create bom', 'edit bom',
            'view soh adjustment', 'create soh adjustment', 'edit soh adjustment',
            'view ice cream orders', 'view salmon orders', 'view fruits and vegetables orders',
            'view category list', 'view wip list', 'view menu categories', 'view uom conversions',
            'view inventory categories', 'view unit of measurements', 'view branches', 'view suppliers', 'view cost centers',
            'view top 10 inventories', 'view days inventory outstanding', 'view days payable outstanding',
            'view sales report', 'view inventories report', 'view upcoming inventories',
            'view account payable', 'view cost of goods',
            'view store transactions', 'create store transactions', 'view store transaction', 'edit store transactions',
            // NEW: POSMasterfileBOM permissions for store representative
            'view POSMasterfile BOM list', 'view POSMasterfile BOM', 'create POSMasterfile BOM', 'edit POSMasterfile BOM', 'import POSMasterfile BOM', 'export POSMasterfile BOM',
        ]);

        // Assign specific permissions to 'request approver'
        $role3->syncPermissions([
            'view orders for approval list', 'view order for approval', 'approve/decline order request',
            'view approved received items', 'view approved received item', 'view received orders for approval list',
            'view approved order for approval', 'approve image attachments',
            // Added for consistency
            'view emergency order approval', 'approve emergency order', 'decline emergency order',
            'view additional order approval', 'approve additional order', 'decline additional order',
            'view store transactions approval', 'approve store transactions', 'decline store transactions',
            // NEW: Export permission for CS Approval List for 'request approver'
            'export orders for cs approval list',
        ]);
    }
}
