<?php

namespace App\Http\Controllers;

use App\Exports\RolesExport;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    public function index()
    {

        $search = request('search');
        $query = Role::query()->with('permissions');

        if ($search)
            $query->where('name', 'like', "%$search%");
        $roles = $query->latest()->paginate(10);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'filters' => request()->only(['search'])
        ]);
    }


    public function edit($id)
    {
        $role = Role::with('permissions')->findOrFail($id);

        $groupedPermissions = $this->getPermissionsGroup();


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
                'message' => "Can't delete this role because there are users associated with it."
            ]);
        }
        try {
            $role->delete();
        } catch (Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
        return to_route('roles.index');
    }


    public function create()
    {
        $groupedPermissions = $this->getPermissionsGroup();
        $groupedPermissions = collect($groupedPermissions)->map(function ($permissions) {
            return $permissions->pluck('name', 'id');
        });

        return Inertia::render('Roles/Create', [
            'permissions' => $groupedPermissions
        ]);
    }
    public function store(StoreRoleRequest $request)
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            $role = Role::create(['name' => $validated['name']]);
            $permissions = Permission::whereIn('id', $validated['selectedPermissions'])->get();
            $role->syncPermissions($permissions);
        } catch (Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        DB::commit();

        return redirect()->route('roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function export(Request $request)
    {
        $search = $request->input('search');

        return Excel::download(
            new RolesExport($search),
            'roles-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $validated = $request->validated();
        DB::beginTransaction();
        try {
            $role->update([
                'name' => $validated['name']
            ]);
            $permissions = Permission::whereIn('id', $validated['selectedPermissions'])->get();
            $role->syncPermissions($permissions);
            DB::commit();
        } catch (Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
        return redirect()->route('roles.index')
            ->with('success', 'Role updated successfully.');
    }

    public function getPermissionsGroup()
    {
        $permissions = Permission::all();
        return [
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
                return str_contains($permission->name, 'order for approval list') ||
                    str_contains($permission->name, 'order for approval') ||
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
    }
}
