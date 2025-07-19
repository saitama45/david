<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash; // Import Hash facade
use Spatie\Permission\Models\Role; // Import Role model if not already

class UserService
{
    public function createUser(array $data)
    {
        // Hash the password before creating the user
        $data['password'] = Hash::make($data['password']); // Use Hash::make
        DB::beginTransaction();
        try {
            $user = User::create($data);
            $roles = Role::whereIn('id', $data['roles'])->get();
            $user->assignRole($roles);
            $user->store_branches()->attach($data['assignedBranches']);

            // Assign suppliers (NEW)
            if (isset($data['assignedSuppliers'])) {
                $user->suppliers()->attach($data['assignedSuppliers']);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e; // Re-throw the exception to be caught in the controller
        }
    }

    public function updateUser(array $data, User $user)
    {
        DB::beginTransaction();
        try {
            // Update user data, excluding password if not provided or empty
            $updateData = [
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'phone_number' => $data['phone_number'],
                'email' => $data['email'],
                'remarks' => $data['remarks'] ?? null,
            ];

            if (isset($data['password']) && !empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            $user->update($updateData);

            $roles = Role::whereIn('id', $data['roles'])->get();
            $user->syncRoles($roles);
            $user->store_branches()->sync($data['assignedBranches']);

            // Sync suppliers (NEW)
            if (isset($data['assignedSuppliers'])) {
                $user->suppliers()->sync($data['assignedSuppliers']);
            } else {
                $user->suppliers()->sync([]); // Detach all suppliers if none are provided
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e; // Re-throw the exception to be caught in the controller
        }
    }

    public function deleteUser(User $user)
    {
        // Start transaction for delete operation as well
        DB::beginTransaction();
        try {
            $user->load(['usage_records', 'store_orders']);
            if ($user->usage_records->count() > 0 || $user->store_orders->count() > 0) {
                // It's better to throw an exception here and let the controller handle the back() withErrors
                throw new \Exception("Can't delete this user because there are data associated with it.");
            }
            $user->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e; // Re-throw the exception
        }
    }

    public function getUsersList()
    {
        $search = request('search');
        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'is_active'])
            // Eager load suppliers relationship here
            ->withOnly(['roles:name', 'suppliers:id,name']); // Assuming 'name' is a relevant field for suppliers
        if ($search) {
            $query->whereAny(['first_name', 'last_name', 'email'], 'like', "%$search%");
        }
        return $query->latest()->paginate(10)->withQueryString();
    }

    public function getUserFromTemplate()
    {
        $templateId = request('templateId');
        // Load suppliers relationship if a template user is being fetched
        return $templateId ? User::with(['roles', 'store_branches', 'suppliers'])->findOrFail($templateId) : null;
    }
}