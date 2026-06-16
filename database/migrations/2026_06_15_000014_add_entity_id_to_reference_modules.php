<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Reference modules entity scoping (Masterfiles, Suppliers, Menus,
 * UoM, WIP, Month-End, Sales Budgets). All existing rows belong to the single
 * current entity (Nonos), so they are backfilled directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('entity_id')->nullable()->after('id')->constrained('entities')->nullOnDelete();
            });
        }

        $nonosId = DB::table('entities')->where('code', 'NONOS')->value('id');
        if ($nonosId) {
            foreach ($this->tables() as $table) {
                DB::table($table)->whereNull('entity_id')->update(['entity_id' => $nonosId]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['entity_id']);
                $t->dropColumn('entity_id');
            });
        }
    }

    private function tables(): array
    {
        return [
            'suppliers',
            'supplier_items',
            'sap_masterfiles',
            'pos_masterfiles',
            'pos_masterfiles_bom',
            'menus',
            'menu_categories',
            'menu_ingredients',
            'unit_of_measurements',
            'unit_of_mesurement_conversions',
            'cost_centers',
            'delivery_schedules',
            'orders_cutoff',
            'wips',
            'wip_ingredients',
            'month_end_count_templates',
            'month_end_schedules',
            'month_end_count_items',
            'sales_budgets',
        ];
    }
};
