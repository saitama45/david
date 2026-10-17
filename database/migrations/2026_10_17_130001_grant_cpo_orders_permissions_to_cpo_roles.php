<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Users who ordered CPO in Mass Orders keep that access in CPO Orders. Every role held by a
     * user assigned the CPO template gets "view cpo orders" when it can view Mass Orders, and
     * "create cpo orders" when it can create them, so the boxes are ticked in Roles.
     *
     * Placing a CPO order still needs the CPO template assigned to the user (as in Mass Orders),
     * so the other members of such a role can see CPO Orders but not place one.
     */
    public function up(): void
    {
        $roleIds = DB::table('user_suppliers as us')
            ->join('model_has_roles as mr', 'mr.model_id', '=', 'us.user_id')
            ->where('mr.model_type', \App\Models\User::class)
            ->where('us.supplier_code', 'CPO')
            ->distinct()
            ->pluck('mr.role_id');

        $grants = [
            'view mass orders' => Permission::firstOrCreate(['name' => 'view cpo orders', 'guard_name' => 'web']),
            'create mass orders' => Permission::firstOrCreate(['name' => 'create cpo orders', 'guard_name' => 'web']),
        ];

        foreach (Role::whereIn('id', $roleIds)->get() as $role) {
            foreach ($grants as $massOrderPermission => $cpoPermission) {
                if ($role->hasPermissionTo($massOrderPermission) && ! $role->hasPermissionTo($cpoPermission)) {
                    $role->givePermissionTo($cpoPermission);
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left granted: removing it could take away grants made in Roles since.
    }
};
