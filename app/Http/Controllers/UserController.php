<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->paginate(10);

        return Inertia::render('User/Index', [
            'users' => $users
        ]);
    }

    public function create()
    {
        $roles = UserRole::values();
        $branches = StoreBranch::options();
        foreach ($roles as &$role) {
            $role = Str::headline($role);
        }
        return Inertia::render('User/Create', [
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

        if (in_array('so_encoder', $validated['roles']))
            $validatedAssignedStoreBranches = $request->validate([
                'assignedBranches' => ['required'],
            ]);


        $validated['password'] = 'password';

        DB::beginTransaction();
        $user = User::create($validated);
        $user->assignRole($validated['roles']);
        if (in_array('so_encoder', $validated['roles'])) {
            $validatedAssignedStoreBranches = $request->validate([
                'assignedBranches' => ['required', 'array'],
            ]);



            $user->store_branches()->attach($validatedAssignedStoreBranches['assignedBranches']);
        }

        DB::commit();

        return redirect()->route('users.index');
    }

    public function show($id)
    {
        $user = User::with('roles')->find($id);
        return Inertia::render('User/Show', [
            'user' => $user
        ]);
    }
}
