<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register the Entities admin link in the DB-backed sidebar so it is
 * manageable (reorder/rename/toggle) in the sidebar-layout settings.
 * Idempotent — safe to run on any environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sidebar_menu_settings')) {
            return;
        }

        DB::table('sidebar_menu_settings')->updateOrInsert(
            ['menu_key' => 'administration.entities'],
            [
                'parent_key' => 'administration',
                'sort_order' => 0,
                'is_active' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('sidebar_menu_settings')) {
            DB::table('sidebar_menu_settings')->where('menu_key', 'administration.entities')->delete();
        }
    }
};
