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

    public function updateUser(array $data, User $user)
    {
        DB::beginTransaction();
        $user->update([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'phone_number' => $data['phone_number'],
            'email' => $data['email'],
            'remarks' => $data['remarks'] ?? null,
        ]);

        $roles = Role::whereIn('id', $data['roles'])->get();
        $user->syncRoles($roles);
        $user->store_branches()->sync($data['assignedBranches']);

        DB::commit();
    }
}
