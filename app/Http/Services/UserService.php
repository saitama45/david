<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserService
{
    public function createUser(array $data)
    {
        $data['password'] = 'password';
        DB::beginTransaction();
        $user = User::create($data);
        $roles = Role::whereIn('id', $data['roles'])->get();
        $user->assignRole($roles);
        $user->store_branches()->attach($data['assignedBranches']);
        DB::commit();
    }
}
