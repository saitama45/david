<?php

namespace App\Http\Services;

use App\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleService
{

    public function createRole(array $data)
    {
        $role = Role::create(['name' => $data['name']]);
        $permissions = Permission::whereIn('id', $data['selectedPermissions'])->get();
        $role->syncPermissions($permissions);
    }
}
