<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view sales budget uploader']);
        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        $permission = \Spatie\Permission\Models\Permission::where('name', 'view sales budget uploader')->first();
        if ($permission) {
            $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->first();
            if ($adminRole) {
                $adminRole->revokePermissionTo($permission);
            }
            $permission->delete();
        }
    }
};
