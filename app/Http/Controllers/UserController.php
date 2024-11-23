<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
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
        foreach ($roles as &$role) {
            $role = Str::headline($role);
        }
        return Inertia::render('User/Create', [
            'roles' => $roles
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required'],
            'email' => ['required', 'unique:users,email'],
            'role' => ['required'],
            'remarks' => ['sometimes'],
        ]);

        $validated['password'] = 'password';

        User::create($validated);

        return redirect()->route('users.index');
    }
}
