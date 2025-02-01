<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Support\Str;


class Role extends SpatieRole
{
    public function scopeRolesOption(Builder $query)
    {
        $roles = $query->select(['id', 'name'])->pluck('name', 'id');
        foreach ($roles as &$role) {
            $role = Str::headline($role);
        }
        return $roles;
    }
}
