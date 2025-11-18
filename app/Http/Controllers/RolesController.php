<?php

namespace App\Http\Controllers;

use App\Exports\RoleExport;
use App\Exports\RolesExport;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Services\RoleService;
use App\Models\Role;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;



class RolesController extends Controller
{
    protected $roleService;

    public function __construct(RoleService $roleService)
    {
        $this->roleService = $roleService;
    }
    public function index()
    {
        return Inertia::render('Roles/Index', [
            'roles' => $this->roleService->getRolesList(),
            'filters' => request()->only(['search'])
        ]);
    }

    public function show(Role $role)
    {
        $role->load(['permissions']);
        $groupedPermissions = $this->roleService->getPermissionsGroup();
        return Inertia::render('Roles/Show', [
            'role' => $role,
            'permissions' => $groupedPermissions,
        ]);
    }

    public function edit(Role $role)
    {
        $role->load(['permissions']);
        // Pass the grouped permissions from the service to the frontend
        $groupedPermissions = $this->roleService->getPermissionsGroup();
        return Inertia::render('Roles/Edit', [
            'permissions' => $groupedPermissions,
            'role' => $role
        ]);
    }


    public function destroy(Role $role)
    {
        try {
            $this->roleService->deleteRole($role);
        } catch (Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
        return to_route('roles.index');
    }


    public function create()
    {
        // Pass the grouped permissions from the service to the frontend for the create page as well
        $groupedPermissions = $this->roleService->getPermissionsGroup();
        return Inertia::render('Roles/Create', [
            'permissions' => $groupedPermissions
        ]);
    }

    public function store(StoreRoleRequest $request)
    {
        try {
            $this->roleService->createRole($request->validated());
        } catch (Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
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

    public function exportRole(Role $role)
    {
        $groupedPermissions = $this->roleService->getPermissionsGroup();
        return Excel::download(
            new RoleExport($role, $groupedPermissions),
            'role-' . $role->name . '-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        try {
            $this->roleService->updateRole($request->validated(), $role);
        } catch (Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage()
            ]);
        }
        return redirect()->route('roles.index')
            ->with('success', 'Role updated successfully.');
    }
}
