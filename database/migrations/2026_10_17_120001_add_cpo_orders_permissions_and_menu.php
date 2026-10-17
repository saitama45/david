<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['view cpo orders', 'create cpo orders'];

    private const MENU_KEY = 'ordering.mass.cpo-orders';

    /**
     * CPO Orders becomes its own page under Regular Mass Orders, right below Mass Orders.
     */
    public function up(): void
    {
        $adminRole = \Spatie\Permission\Models\Role::where('name', 'admin')->first();

        foreach (self::PERMISSIONS as $name) {
            $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $adminRole?->givePermissionTo($permission);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Place the menu right below Mass Orders when the sidebar order was saved; without a
        // saved order the sidebar's own order already puts it there.
        $massOrders = DB::table('sidebar_menu_settings')->where('menu_key', 'ordering.mass.mass-orders')->first();

        if ($massOrders && ! DB::table('sidebar_menu_settings')->where('menu_key', self::MENU_KEY)->exists()) {
            DB::table('sidebar_menu_settings')
                ->where('parent_key', 'ordering.mass')
                ->where('sort_order', '>', $massOrders->sort_order)
                ->increment('sort_order');

            DB::table('sidebar_menu_settings')->insert([
                'menu_key' => self::MENU_KEY,
                'parent_key' => 'ordering.mass',
                'custom_label' => null,
                'sort_order' => $massOrders->sort_order + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // CPO orders always go to Mass Orders Approval; keep the Ordering Template Approval
        // switch showing that.
        DB::table('suppliers')->where('supplier_code', 'CPO')->update(['is_forapproval_massorders' => true]);
    }

    public function down(): void
    {
        // Permissions, the menu row and the approval switch are left in place: removing them
        // would discard role grants and sidebar choices made since.
    }
};
