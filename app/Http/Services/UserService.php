<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log; // CRITICAL FIX: Import Log facade for debugging

class UserService
{
    public function createUser(array $data)
    {
        // Password hashing is handled by the 'hashed' cast on the User model.
        DB::beginTransaction();
        try {
            $user = User::create($data);
            $roles = Role::whereIn('id', $data['roles'])->get();
            $user->assignRole($roles);
            $user->store_branches()->attach($data['assignedBranches']);

            if (isset($data['assignedSuppliers'])) {
                $user->suppliers()->attach($data['assignedSuppliers']);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateUser(array $data, User $user)
    {
        DB::beginTransaction();
        try {
            $updateData = [
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'phone_number' => $data['phone_number'],
                'email' => $data['email'],
                'remarks' => $data['remarks'] ?? null,
            ];

            if (isset($data['password']) && !empty($data['password'])) {
                $updateData['password'] = $data['password']; // Assign plain password, model cast will hash it
            }

            $user->update($updateData);

            $roles = Role::whereIn('id', $data['roles'])->get();
            $user->syncRoles($roles);
            $user->store_branches()->sync($data['assignedBranches']);

            if (isset($data['assignedSuppliers'])) {
                $user->suppliers()->sync($data['assignedSuppliers']);
            } else {
                $user->suppliers()->sync([]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteUser(User $user)
    {
        DB::beginTransaction();
        try {
            $user->load(['usage_records', 'store_orders']);
            if ($user->usage_records->count() > 0 || $user->store_orders->count() > 0) {
                throw new \Exception("Can't delete this user because there are data associated with it.");
            }
            $user->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getUsersList()
    {
        $search = request('search');
        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'is_active'])
            ->withOnly(['roles:name', 'suppliers:supplier_code,name']);
        if ($search) {
            $query->whereAny(['first_name', 'last_name', 'email'], 'like', "%$search%");
        }
        return $query->latest()->paginate(10)->withQueryString();
    }

    public function getUserFromTemplate()
    {
        $templateId = request('templateId');
        return $templateId ? User::with(['roles', 'store_branches', 'suppliers'])->findOrFail($templateId) : null;
    }
}
