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
        $permissions = Permission::select('name', 'id')->pluck('name', 'id');
        return Inertia::render('Roles/Edit', [
            'permissions' => $permissions,
            'role' => $role
        ]);
    }

    public function create()
    {
        $permissions = Permission::select('name', 'id')->pluck('name', 'id');

        return Inertia::render('Roles/Create', [
            'permissions' => $permissions
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
