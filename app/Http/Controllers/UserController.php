<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Models\User;
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
}
