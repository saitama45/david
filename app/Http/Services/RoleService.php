<?php

namespace App\Http\Services;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class RoleService
{

    public function createRole(array $data)
    {
        DB::beginTransaction();
        $role = Role::create(['name' => $data['name']]);
        $permissions = Permission::whereIn('id', $data['selectedPermissions'])->get();
        $role->syncPermissions($permissions);
        DB::commit();
    }

    public function updateRole(array $data, Role $role)
    {
        DB::beginTransaction();
        $role->update([
            'name' => $data['name']
        ]);
        $permissions = Permission::whereIn('id', $data['selectedPermissions'])->get();
        $role->syncPermissions($permissions);
        DB::commit();
    }

    public function getPermissionsGroup()
    {
        $permissions = Permission::all();

        $groupedPermissions = [
            'user' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'users');
            }),

            'roles' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'roles');
            }),

            'dts_delivery_schedules' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'dts delivery schedules');
            }),

            'store_orders' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'store order');
            }),

            'dts_orders' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'dts order');
            }),

            'orders_approval' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'view order for approval') ||
                    str_contains($permission->name, 'view orders for approval list') ||
                    str_contains($permission->name, 'approve/decline order request') &&  !str_contains($permission->name, 'cs');
            }),

            'cs_review_list' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'orders for cs approval') ||
                    str_contains($permission->name, 'cs approve/decline order request') ||
                    str_contains($permission->name, 'order for cs approval');
            }),

            'approved_orders' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'approved order') &&
                    !str_contains($permission->name, 'for approval');
            }),

            'approvals' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'received orders for approval') ||
                    str_contains($permission->name, 'approve received orders') ||
                    str_contains($permission->name, 'approve image attachments');
            }),

            'approved_received_items' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'approved received item');
            }),

            'store_transactions' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'store transaction');
            }),

            'items' => $permissions->filter(function ($permission) {
                return !str_contains($permission->name, 'approved received item') && (str_contains($permission->name, 'items') ||
                    str_contains($permission->name, 'item')) && !str_contains($permission->name, 'order');
            }),

            'bom' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'bom');
            }),

            'stock_management' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'stock');
            }),

            'items_order_summary' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'items order summary') ||
                    str_contains($permission->name, 'ice cream orders') ||
                    str_contains($permission->name, 'salmon orders') ||
                    str_contains($permission->name, 'fruits and vegetables orders');
            }),

            'manage_references' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'references');
            })
        ];

        return collect($groupedPermissions)->map(function ($permissions) {
            return $permissions->pluck('name', 'id');
        });
    }

    public function getRolesList()
    {
        $search = request('search');
        $query = Role::query()->with('permissions');

        if ($search)
            $query->where('name', 'like', "%$search%");
        return $query->latest()->paginate(10);
    }

    public function deleteRole(Role $role)
    {
        $role->load(['users']);
        if ($role->users->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this role because there are users associated with it."
            ]);
        }
        $role->delete();
    }
}
