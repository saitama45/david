<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Levels 2 and 3; Level 1 keeps "approve mass order" / "reject mass order". */
    private const PERMISSIONS = [
        'approve mass order level 2',
        'reject mass order level 2',
        'approve mass order level 3',
        'reject mass order level 3',
    ];

    /**
     * Mass Orders Approval in levels. Each ordering template sets how many levels approve its
     * mass orders (Ordering Template Approval, 1 to 3, default 1 = the flow before this). An order
     * stays pending until its last level approves: approved_level counts the levels passed, and
     * approval_levels fixes the number of levels at the order's first approval, so changing the
     * template later only affects orders nobody has approved yet. store_order_approvals keeps who
     * approved or rejected each level.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedTinyInteger('mass_order_approval_levels')->default(1);
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('approval_levels')->nullable();
            $table->unsignedTinyInteger('approved_level')->default(0);
        });

        Schema::create('store_order_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_order_id')->constrained('store_orders');
            $table->unsignedTinyInteger('level');
            $table->string('action', 20);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->dateTime('acted_at');
            $table->timestamps();

            $table->index(['store_order_id', 'level'], 'store_order_approvals_order_level_index');
        });

        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->first();

        foreach (self::PERMISSIONS as $name) {
            $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $adminRole?->givePermissionTo($permission);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions are left in place: removing them would discard role grants made since.
        Schema::dropIfExists('store_order_approvals');

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['approval_levels', 'approved_level']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('mass_order_approval_levels');
        });
    }
};
