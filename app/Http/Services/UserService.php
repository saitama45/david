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

    public function deleteUser(User $user)
    {
        $user->load(['usage_records', 'store_orders']);
        if ($user->usage_records->count() > 0 || $user->store_orders->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this user because there are data associated with it."
            ]);
        }
        $user->delete();
    }

    public function getUsersList()
    {
        $search = request('search');
        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'is_active'])
            ->withOnly(['roles:name']);
        if ($search) {
            $query->whereAny(['first_name', 'last_name', 'email'], 'like', "%$search%");
        }
        return $query->latest()->paginate(10)->withQueryString();
    }

    public function getUserFromTemplate()
    {
        
        $templateId = request('templateId');
        return $templateId ? User::with(['roles', 'store_branches'])->findOrFail($templateId) : null;
    }
}
