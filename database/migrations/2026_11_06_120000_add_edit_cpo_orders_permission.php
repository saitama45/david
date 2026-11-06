<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * CPO Orders gets an Edit button. Its permission is ticked for admin, and for every role that
     * may already place CPO orders and edit mass orders, so those users keep the edit they had
     * when CPO was ordered in Mass Orders.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'edit cpo orders', 'guard_name' => 'web']);

        Role::where('name', 'admin')->first()?->givePermissionTo($permission);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'create cpo orders'))
            ->whereHas('permissions', fn ($q) => $q->where('name', 'edit mass orders'))
            ->get();

        foreach ($roles as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: removing it could take away grants made in Roles since.
    }
};
