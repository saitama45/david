<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Administration > Email Notifications (the no-reply mailbox and the commit variance email).
     * Only admin gets it; anyone else is given it in Roles.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage email notifications', 'guard_name' => 'web']);
        Role::where('name', 'admin')->first()?->givePermissionTo($permission);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: removing it could take away grants made in Roles since.
    }
};
