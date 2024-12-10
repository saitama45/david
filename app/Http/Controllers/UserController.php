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
        $users = User::paginate(10);

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
            'name' => ['required'],
            'email' => ['required', 'unique:users,email'],
            'roles' => ['required'],
            'remarks' => ['sometimes'],

        ]);

        if ($validated['role'] === 'so_encoder')
            $validatedAssignedStoreBranches = $request->validate([
                'assignedBranches' => ['required'],
            ]);


        $validated['password'] = 'password';

        DB::beginTransaction();
        $user = User::create($validated);
        if ($validated['role'] === 'so_encoder')
            $user->store_branches()->attach($validatedAssignedStoreBranches['assignedBranches']);
        foreach ($validated['roles'] as $role) {
            $user->user_role()->create([
                'role' => $role
            ]);
        }
        DB::commit();

        return redirect()->route('users.index');
    }
}
