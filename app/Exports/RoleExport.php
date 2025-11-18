<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Spatie\Permission\Models\Role;

class RoleExport implements FromArray, WithHeadings
{
    use Exportable;

    protected $role;
    protected $groupedPermissions;
    protected $rolePermissionIds;

    public function __construct(Role $role, array $groupedPermissions)
    {
        $this->role = $role;
        $this->groupedPermissions = $groupedPermissions;
        // Eager load permissions and get their IDs for efficient lookup
        $this->rolePermissionIds = $role->permissions->pluck('id')->flip();
    }

    public function array(): array
    {
        $data = [];

        foreach ($this->groupedPermissions as $mainCategory => $subCategories) {
            foreach ($subCategories as $subCategory => $permissions) {
                foreach ($permissions as $permission) {
                    $data[] = [
                        'Role Name' => $this->role->name,
                        'Category' => $mainCategory,
                        'Sub-Category' => $subCategory,
                        'Permission' => $permission['name'],
                        'Has Permission' => $this->rolePermissionIds->has($permission['id']) ? 'Yes' : 'No',
                    ];
                }
            }
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'Role Name',
            'Category',
            'Sub-Category',
            'Permission',
            'Has Permission',
        ];
    }
}
