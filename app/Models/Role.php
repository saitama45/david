<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;


class Role extends SpatieRole
{
    public function scopeRolesOption(Builder $query)
    {
        // Names are shown as stored. Str::headline mangles the acronym-heavy role
        // names this app uses ("GSI-CS" became "G S I C S"), and User/Edit already
        // lists them raw, so this keeps every role picker reading the same way.
        return $query->select(['id', 'name'])->get()->pluck('name', 'id');
    }
}
