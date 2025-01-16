<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    public function index()
    {

        $search = request('search');
        $query = Role::query()->with(with('permissions'));

        if ($search)
            $query->where('name', 'like', "%$search%");
        $roles = $query->paginate(10);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'filters' => request()->only(['search'])
        ]);
    }


    public function edit($id)
    {
        $role = Role::with('permissions')->findOrFail($id);
        $permissions = Permission::all();

        $groupedPermissions = [
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
                return str_contains($permission->name, 'order for approval') ||
                    str_contains($permission->name, 'orders for approval list') ||
                    str_contains($permission->name, 'approve/decline order request');
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
                return str_contains($permission->name, 'item') &&
                    !str_contains($permission->name, 'received item');
            }),

            'menu' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'menu');
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

            'user' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'user');
            }),

            'manage_references' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'references');
            })
        ];

        // Transform each permission collection to array of [id => name]
        $groupedPermissions = collect($groupedPermissions)->map(function ($permissions) {
            return $permissions->pluck('name', 'id');
        });
        return Inertia::render('Roles/Edit', [
            'permissions' => $groupedPermissions,
            'role' => $role
        ]);
    }


    public function destroy($id)
    {
        $role = Role::with('users')->findOrFail($id);
        if ($role->users->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this category because there are users associated with it."
            ]);
        }
        $role->delete();
        return to_route('roles.index');
    }


    public function create()
    {
        $permissions = Permission::all();

        $groupedPermissions = [
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
                return str_contains($permission->name, 'order for approval') ||
                    str_contains($permission->name, 'orders for approval list') ||
                    str_contains($permission->name, 'approve/decline order request');
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
                return str_contains($permission->name, 'item') &&
                    !str_contains($permission->name, 'received item');
            }),

            'menu' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'menu');
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

            'user' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'user');
            }),

            'manage_references' => $permissions->filter(function ($permission) {
                return str_contains($permission->name, 'references');
            })
        ];

        // Transform each permission collection to array of [id => name]
        $groupedPermissions = collect($groupedPermissions)->map(function ($permissions) {
            return $permissions->pluck('name', 'id');
        });

        return Inertia::render('Roles/Create', [
            'permissions' => $groupedPermissions
        ]);
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles'],
            'selectedPermissions' => ['required', 'array'],
            'selectedPermissions.*' => ['exists:permissions,id'],
        ]);

        DB::beginTransaction();
        $role = Role::create(['name' => $validated['name']]);
        $permissions = Permission::whereIn('id', $validated['selectedPermissions'])->get();
        $role->syncPermissions($permissions);
        DB::commit();

        return redirect()->route('roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'selectedPermissions' => ['required', 'array'],
            'selectedPermissions.*' => ['exists:permissions,id'],
        ]);

        DB::beginTransaction();
        $role = Role::findOrFail($id);
        $role->update([
            'name' => $validated['name']
        ]);
        $permissions = Permission::whereIn('id', $validated['selectedPermissions'])->get();
        $role->syncPermissions($permissions);
        DB::commit();
        return redirect()->route('roles.index')
            ->with('success', 'Role updated successfully.');
    }
}
