<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = User::query()->with('roles');
        if ($search) {
            $query->whereAny(['first_name', 'last_name', 'email'], 'like', "%$search%");
        }
        $users = $query->paginate(10);

        return Inertia::render('User/Index', [
            'users' => $users,
            'filters' => request()->only(['search'])
        ]);
    }

    public function create()
    {
        $roles = Role::select(['id', 'name'])->pluck('name', 'id');
        $branches = StoreBranch::options();
        foreach ($roles as &$role) {
            $role = Str::headline($role);
        }
        return Inertia::render('User/Create', [
            'roles' => $roles,
            'branches' => $branches
        ]);
    }

    public function edit($id)
    {
        $user = User::with(['roles', 'store_branches'])->find($id);
        $roles = Role::select(['id', 'name'])->pluck('name', 'id');
        $branches = StoreBranch::options();
        return Inertia::render('User/Edit', [
            'user' => $user,
            'roles' => $roles,
            'branches' => $branches
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required'],
            'middle_name' => ['sometimes'],
            'last_name' => ['required'],
            'phone_number' => ['required'],
            'email' => ['required', 'unique:users,email'],
            'roles' => ['required'],
            'remarks' => ['sometimes'],

        ]);



        $validated['password'] = 'password';

        DB::beginTransaction();
        $user = User::create($validated);
        $roles = Role::whereIn('id', $validated['roles'])->get();
        $user->assignRole($roles);
        $validatedAssignedStoreBranches = $request->validate([
            'assignedBranches' => ['required', 'array'],
        ]);
        $user->store_branches()->attach($validatedAssignedStoreBranches['assignedBranches']);
        DB::commit();

        return redirect()->route('users.index');
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'first_name' => ['required'],
            'middle_name' => ['sometimes'],
            'last_name' => ['required'],
            'phone_number' => ['required'],
            'email' => ['required', 'unique:users,email,' . $id],
            'roles' => ['required', 'array'],
            'remarks' => ['sometimes'],
            'assignedBranches' => ['sometimes', 'array'],
        ]);

        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);

            $user->update([
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'phone_number' => $validated['phone_number'],
                'email' => $validated['email'],
                'remarks' => $validated['remarks'] ?? null,
            ]);
            $roles = Role::whereIn('id', $validated['roles'])->get();
            $user->syncRoles($roles);

            $user->store_branches()->sync($validated['assignedBranches']);

            DB::commit();

            return redirect()->route('users.index');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $user = User::with('roles')->find($id);
        return Inertia::render('User/Show', [
            'user' => $user
        ]);
    }
}
