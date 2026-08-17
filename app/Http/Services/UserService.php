<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Exception;

class UserService
{
    /**
     * Sanitize branch IDs coming from frontend: keep only numeric values and cast to int.
     */
    protected function sanitizeBranchIds(array $values = null): array
    {
        if (empty($values)) {
            return [];
        }
        $filtered = array_values(array_filter($values, function ($v) {
            // treat numeric strings and integers as valid ids
            return is_numeric($v);
        }));
        return array_map('intval', $filtered);
    }

    /**
     * Sanitize supplier values coming from frontend: remove sentinel values like 'all' and empty strings.
     * Keep string codes or numeric ids as-is (trimmed).
     */
    protected function sanitizeSupplierValues(array $values = null): array
    {
        if (empty($values)) {
            return [];
        }
        $filtered = array_values(array_filter($values, function ($v) {
            if ($v === null) return false;
            $v = trim((string) $v);
            // drop special marker values
            if ($v === '' || strtolower($v) === 'all') return false;
            return true;
        }));
        return $filtered;
    }

    /**
     * Sanitize role ids: keep only numeric IDs (since we'll look up names by id).
     */
    protected function sanitizeRoleIds(array $values = null): array
    {
        if (empty($values)) return [];
        $filtered = array_values(array_filter($values, function ($v) {
            return is_numeric($v);
        }));
        return array_map('intval', $filtered);
    }

    public function createUser(array $data)
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name'    => $data['first_name'] ?? null,
                'middle_name'   => $data['middle_name'] ?? null,
                'last_name'     => $data['last_name'] ?? null,
                'phone_number'  => $data['phone_number'] ?? null,
                'email'         => $data['email'] ?? null,
                'password'      => $data['password'] ?? null,
                'remarks'       => $data['remarks'] ?? null,
                'is_active'     => $data['is_active'] ?? 1,
            ]);

            // Roles: convert IDs -> names
            if (isset($data['roles']) && is_array($data['roles'])) {
                $roleIds = $this->sanitizeRoleIds($data['roles']);
                $roleNames = [];
                if (!empty($roleIds)) {
                    $roleNames = Role::whereIn('id', $roleIds)->pluck('name')->toArray();
                }
                if (!empty($roleNames)) {
                    $user->assignRole($roleNames);
                } else {
                    $user->syncRoles([]);
                }
            } else {
                $user->syncRoles([]);
            }

            // Assigned branches: ensure numeric IDs only
            $branchIds = $this->sanitizeBranchIds($data['assignedBranches'] ?? []);
            $user->store_branches()->sync($branchIds);

            // Assigned suppliers: remove marker values like 'all', keep codes or ids
            $supplierVals = $this->sanitizeSupplierValues($data['assignedSuppliers'] ?? []);
            $user->suppliers()->sync($supplierVals);

            return $user;
        });
    }

    public function updateUser(array $data, User $user)
    {
        return DB::transaction(function () use ($data, $user) {
            $updateData = [
                'first_name' => $data['first_name'] ?? $user->first_name,
                'middle_name' => $data['middle_name'] ?? $user->middle_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'phone_number' => $data['phone_number'] ?? $user->phone_number,
                'email' => $data['email'] ?? $user->email,
                'remarks' => $data['remarks'] ?? $user->remarks,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ];

            if (isset($data['password']) && !empty($data['password'])) {
                $updateData['password'] = $data['password'];
            }

            $user->update($updateData);

            // Roles
            if (isset($data['roles']) && is_array($data['roles'])) {
                $roleIds = $this->sanitizeRoleIds($data['roles']);
                $roleNames = $roleIds ? Role::whereIn('id', $roleIds)->pluck('name')->toArray() : [];
                $user->syncRoles($roleNames);
            } else {
                $user->syncRoles([]);
            }

            // Branches
            $branchIds = $this->sanitizeBranchIds($data['assignedBranches'] ?? []);
            $user->store_branches()->sync($branchIds);

            // Suppliers
            $supplierVals = $this->sanitizeSupplierValues($data['assignedSuppliers'] ?? []);
            $user->suppliers()->sync($supplierVals);

            return $user;
        });
    }

    public function deleteUser(User $user)
    {
        // Soft delete: the row is retained (deleted_at is set), so foreign-key
        // references (created_by, approved_by, etc.) stay valid and the audit
        // trail is preserved. Pivot relations are intentionally kept so the
        // user can be restored consistently.
        return $user->delete();
    }

    public function getUsersList()
    {
        $search = request('search');
        $role = request('role');
        $query = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'is_active'])
            ->withOnly(['roles:id,name', 'suppliers:supplier_code,name']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role) {
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('roles.id', $role);
            });
        }

        return $query->latest()->paginate(10)->withQueryString();
    }

    public function getDeletedUsersList()
    {
        $search = request('search');
        $query = User::onlyTrashed()
            ->select(['id', 'first_name', 'last_name', 'email', 'is_active', 'deleted_at'])
            ->withOnly(['roles:id,name']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest('deleted_at')->paginate(10)->withQueryString();
    }

    public function restoreUser($id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();

        return $user;
    }

    public function getUserFromTemplate()
    {
        // Support legacy templateId if still passed
        $templateId = request('templateId');
        if ($templateId) {
            return User::with(['roles', 'store_branches', 'suppliers'])->findOrFail($templateId);
        }

        // Handle roleId for the new role-based template
        $roleId = request('roleId');
        if ($roleId) {
            $user = new User();
            $role = Role::find($roleId);
            if ($role) {
                // Pre-fill the relation so the form gets the role pre-selected
                $user->setRelation('roles', collect([$role]));
                // Initialize empty relations for branches and suppliers
                $user->setRelation('store_branches', collect([]));
                $user->setRelation('suppliers', collect([]));
                return $user;
            }
        }

        return null;
    }
}
