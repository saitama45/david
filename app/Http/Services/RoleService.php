<?php

namespace App\Http\Services;

use App\Models\Role;
use Exception;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class RoleService
{

    public function createRole(array $data)
    {
        DB::beginTransaction();
        try {
            $role = Role::create(['name' => $data['name']]);
            if (isset($data['selectedPermissions']) && !empty($data['selectedPermissions'])) {
                // Fetch Permission models by their IDs
                $permissions = Permission::whereIn('id', $data['selectedPermissions'])->get();
                $role->syncPermissions($permissions);
            } else {
                $role->syncPermissions([]); // Revoke all permissions if none selected
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to create role: " . $e->getMessage());
        }
    }

    public function updateRole(array $data, Role $role)
    {
        DB::beginTransaction();
        try {
            $role->update([
                'name' => $data['name']
            ]);
            if (isset($data['selectedPermissions']) && !empty($data['selectedPermissions'])) {
                // Fetch Permission models by their IDs
                $permissions = Permission::whereIn('id', $data['selectedPermissions'])->get();
                $role->syncPermissions($permissions);
            } else {
                $role->syncPermissions([]); // Revoke all permissions if none selected
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to update role: " . $e->getMessage());
        }
    }

    public function getPermissionsGroup()
    {
        $allPermissions = Permission::all()->keyBy('name'); // Get all permissions keyed by their name

        $groupedPermissions = [];

        // Define the desired hierarchical structure and map permission names to it.
        // Permissions not found in $allPermissions will simply be skipped.
        $permissionStructure = [
            'Settings' => [
                'Users' => [
                    'view users', 'create users', 'edit users', 'view user', 'delete users'
                ],
                'Roles' => [
                    'view roles', 'create roles', 'edit roles', 'delete roles', 'show roles'
                ],
                'DTS Delivery Schedules' => [
                    'view dts delivery schedules', 'edit dts delivery schedules', 'export dts delivery schedules'
                ],
                'DSP Delivery Schedules' => [
                    'view dsp delivery schedules', 'view dsp delivery schedule', 'edit dsp delivery schedules'
                ],
                'Ordering Cut Off' => [
                    'view orders cutoff', 'edit orders cutoff', 'create orders cutoff', 'show orders cutoff'
                ],
                'Month End Schedules' => [
                    'view month end schedules',
                    'create month end schedules',
                    'edit month end schedules',
                    'delete month end schedules',
                ],
                'Month End Count Templates' => [
                    'view month end count templates',
                    'create month end count templates',
                    'edit month end count templates',
                    'delete month end count templates',
                    'export month end count templates',
                    'import month end count templates',
                ],
                'Templates' => [
                    'view templates', 'create templates', 'edit templates', 'delete templates', 'export templates'
                ],
                'Ordering Template Approval' => [
                    'view ordering template approval', 'edit ordering template approval'
                ],
            ],
            'Ordering' => [
                'Store Orders' => [
                    'view store orders', 'create store orders', 'edit store orders', 'view store order', 'export store orders'
                ],
                'Emergency Orders' => [
                    'view emergency orders', 'create emergency orders', 'edit emergency orders', 'delete emergency orders', 'export emergency orders'
                ],
                'Additional Orders' => [
                    'view additional orders', 'create additional orders', 'edit additional orders', 'delete additional orders', 'export additional orders'
                ],
                'Interco Transfers' => [
                    'view interco requests', 'create interco requests', 'edit interco requests', 'approve interco requests', 'commit interco requests', 'export interco requests'
                ],
                'Interco Approval' => [
                    'view interco approvals'
                ],
                'Interco Receiving' => [
                    'view interco receiving', 'receive interco requests', 'view interco receiving approvals', 'export interco receiving'
                ],
                'Store Commits' => [
                    'view store commits', 'commit store orders', 'export store commits'
                ],
                'DTS Orders' => [
                    'view dts orders', 'create dts orders', 'edit dts orders', 'view dts order', 'export dts orders'
                ],
                'Orders Approval (SM)' => [
                    'view orders for approval list', 'view order for approval', 'approve/decline order request'
                ],
                'CS Review List' => [
                    'view orders for cs approval list', 'view order for cs approval', 'cs approve/decline order request',
                    'export orders for cs approval list'
                ],
                'Emergency Order Approval' => [
                    'view emergency order approval', 'approve emergency order', 'decline emergency order'
                ],
                'Additional Order Approval' => [
                    'view additional order approval', 'approve additional order', 'decline additional order'
                ],
                'Mass Orders' => [
                    'view mass orders', 'create mass orders', 'edit mass orders', 'show mass orders', 'view cost mass orders'
                ],
                'Mass Order Approval' => [
                    'view mass order approval', 'approve mass order', 'reject mass order'
                ],
                'CS Mass Commits' => [
                    'view cs mass commits', 'create cs mass commits', 'edit cs mass commits', 'edit finished good commits', 'edit other commits', 'export cs mass commits'
                ],
                'DTS Mass Orders' => [
                    'view dts mass orders', 'create dts mass orders', 'edit dts mass orders', 'export dts mass orders'
                ],
                'CS DTS Mass Commit' => [
                    'view cs dts mass commit', 'edit cs dts mass commit'
                ],
            ],
            'Receiving' => [
                'Direct Receiving' => [
                    'view direct receiving', 'create direct receiving', 'edit direct receiving', 'delete direct receiving', 'export direct receiving'
                ],
                'Approved Orders' => [
                    'view approved orders', 'view approved order', 'receive orders', 'export approved orders'
                ],
                'Receiving Approvals' => [
                    'view received orders for approval list', 'view approved order for approval', 'approve received orders', 'approve image attachments', 'export received orders for approval list'
                ],
                'Confirmed/Approved Received SO' => [
                    'view approved received items', 'view approved received item', 'cancel approved received item', 'export approved received items'
                ],
            ],
            'Sales' => [
                'Store Transactions' => [
                    'view store transactions', 'create store transactions', 'view store transaction', 'edit store transactions', 'export store transactions'
                ],
                'Store Transactions Approval' => [
                    'view store transactions approval', 'approve store transactions', 'decline store transactions'
                ],
            ],
            'Inventory' => [
                'NN Inventory Items' => [
                    'view items list', 'create new items', 'edit items', 'view item', 'delete items', 'export items list'
                ],
                'SAP Masterlist Items' => [
                    'view sapitems list', 'create sapitems', 'edit sapitems', 'delete sapitems', 'export sapitems list'
                ],
                'Supplier Items' => [
                    'view SupplierItems list', 'create SupplierItems', 'edit SupplierItems', 'delete SupplierItems', 'export SupplierItems list'
                ],
                'POS Masterlist' => [
                    'view POSMasterfile list', 'create POSMasterfile', 'edit POSMasterfile', 'delete POSMasterfile', 'export POSMasterfile list'
                ],
                'POS Masterlist BOM' => [
                    'view POSMasterfile BOM list', 'view POSMasterfile BOM', 'create POSMasterfile BOM', 'edit POSMasterfile BOM', 'delete POSMasterfile BOM', 'import POSMasterfile BOM', 'export POSMasterfile BOM',
                ],
                'BOM' => [
                    'view bom list', 'view bom', 'create bom', 'edit bom', 'delete bom', 'export bom list'
                ],
                'Stock Management' => [
                    'view stock management', 'log stock usage', 'add stock quantity', 'view stock management history', 'export stock management'
                ],
                'SOH Adjustment' => [
                    'view soh adjustment', 'create soh adjustment', 'edit soh adjustment', 'delete soh adjustment', 'export soh adjustment'
                ],
                'Wastage Record' => [
                    'view wastage record', 'create wastage record', 'edit wastage record', 'delete wastage record', 'export wastage record', 'view cost wastage record'
                ],
                'Wastage Approval 1st Level' => [
                    'view wastage approval level 1', 'approve wastage level 1', 'cancel wastage approval level 1', 'edit wastage approval level 1', 'delete wastage approval level 1'
                ],
                'Wastage Approval 2nd Level' => [
                    'view wastage approval level 2', 'approve wastage level 2', 'cancel wastage approval level 2', 'edit wastage approval level 2', 'delete wastage approval level 2'
                ],
                'Low on Stocks' => [
                    'view low on stocks', 'export low on stocks'
                ],
                'Month End Count' => [
                    'perform month end count',
                    'download month end count template',
                    'upload month end count transaction',
                    'edit month end count items',
                    'view month end count transaction',
                ],
                'MEC Approval 1st Level' => [
                    'view month end count approvals',
                    'edit month end count approval items',
                    'approve month end count level 1',
                ],
                'MEC Approval 2nd Level' => [
                    'view month end count approvals level 2',
                    'approve month end count level 2',
                ],
            ],
            'Reports' => [
                // CRITICAL FIX: Added Consolidated SO Report here
                'Consolidated SO Report' => [
                    'view consolidated so report', 'export consolidated so report'
                ],
                'Interco Report' => [
                    'view interco report', 'export interco report'
                ],
                // NEW: PMIX Report
                'PMIX Report' => [
                    'view pmix report', 'export pmix report'
                ],
                // NEW: Wastage Report
                'Wastage Report' => [
                    'view wastage report', 'export wastage report'
                ],
                // NEW: Qty Variance / Cost Variance Report
                'Qty Variance / Cost Variance Report' => [
                    'view qty variance cost variance report', 'export qty variance cost variance report'
                ],
                // NEW: Actual Cost / Cost of Goods Sold Report
                'Actual Cost / COGS Report' => [
                    'view actual cost cogs report', 'export actual cost cogs report'
                ],
                // NEW: Delivery Report
                'Delivery Report' => [
                    'view delivery report', 'export delivery report'
                ],
                'Top 10 Inventories' => [
                    'view top 10 inventories', 'export top 10 inventories'
                ],
                'Days Inventory Outstanding' => [
                    'view days inventory outstanding', 'export days inventory outstanding'
                ],
                'Days Payable Outstanding' => [
                    'view days payable outstanding', 'export days payable outstanding'
                ],
                'Sales Report' => [
                    'view sales report', 'export sales report'
                ],
                'Inventories Report' => [
                    'view inventories report', 'export inventories report'
                ],
                'Upcoming Inventories' => [
                    'view upcoming inventories', 'export upcoming inventories'
                ],
                'Account Payable' => [
                    'view account payable', 'export account payable'
                ],
                'Cost Of Goods' => [
                    'view cost of goods', 'export cost of goods'
                ],
                'Item Orders Summary' => [
                    'view items order summary', 'export items order summary'
                ],
                'Ice Cream Orders' => [
                    'view ice cream orders', 'export ice cream orders'
                ],
                'Salmon Orders' => [
                    'view salmon orders', 'export salmon orders'
                ],
                'Fruits and Vegetables Orders' => [
                    'view fruits and vegetables orders', 'export fruits and vegetables orders'
                ],
            ],
            'References' => [
                'Categories' => [
                    'view category list', 'create category', 'edit category', 'delete category', 'export category list'
                ],
                'WIP List' => [
                    'view wip list', 'create wip', 'edit wip', 'delete wip', 'export wip list'
                ],
                'Menu Categories' => [
                    'view menu categories', 'create menu category', 'edit menu category', 'delete menu category', 'export menu categories'
                ],
                'UOM Conversions' => [
                    'view uom conversions', 'create uom conversion', 'edit uom conversion', 'delete uom conversion', 'export uom conversions'
                ],
                'Inventory Categories' => [
                    'view inventory categories', 'create inventory category', 'edit inventory category', 'delete inventory category', 'export inventory categories'
                ],
                'Unit of Measurements' => [
                    'view unit of measurements', 'create unit of measurement', 'edit unit of measurement', 'delete unit of measurement', 'export unit of measurements'
                ],
                'Store Branches' => [
                    'view branches', 'create branch', 'edit branch', 'delete branch', 'export branches'
                ],
                'Suppliers' => [
                    'view suppliers', 'create supplier', 'edit supplier', 'delete supplier', 'export suppliers'
                ],
                'Cost Centers' => [
                    'view cost centers', 'create cost center', 'edit cost center', 'delete cost center', 'export cost centers'
                ],
            ],
        ];

        foreach ($permissionStructure as $mainCategoryLabel => $subCategories) {
            $groupedPermissions[$mainCategoryLabel] = [];
            foreach ($subCategories as $subCategoryLabel => $permissionNames) {
                $currentSubCategoryPermissions = [];
                foreach ($permissionNames as $permissionName) {
                    if ($allPermissions->has($permissionName)) {
                        $permission = $allPermissions->get($permissionName);
                        // Store as an object {id: ..., name: ...}
                        $currentSubCategoryPermissions[] = ['id' => $permission->id, 'name' => $permission->name];
                    }
                }
                // Only add sub-category if it has permissions
                if (!empty($currentSubCategoryPermissions)) {
                    $groupedPermissions[$mainCategoryLabel][$subCategoryLabel] = $currentSubCategoryPermissions;
                }
            }
            // If a main category ends up empty after filtering, remove it
            if (empty($groupedPermissions[$mainCategoryLabel])) {
                unset($groupedPermissions[$mainCategoryLabel]);
            }
        }

        // Sort main categories alphabetically by their keys
        ksort($groupedPermissions);

        // Sort sub-categories alphabetically by their keys
        foreach ($groupedPermissions as $mainCategoryLabel => $subCategories) {
            ksort($groupedPermissions[$mainCategoryLabel]);
        }

        return $groupedPermissions;
    }

    public function getRolesList()
    {
        $search = request('search');
        $query = Role::query()->with('permissions');

        if ($search) {
            $query->where('name', 'like', "%$search%");
        }
        return $query->latest()->paginate(10);
    }

    public function deleteRole(Role $role)
    {
        $role->load(['users']);
        if ($role->users->count() > 0) {
            throw new Exception("Can't delete this role because there are users associated with it.");
        }
        $role->delete();
    }
}
