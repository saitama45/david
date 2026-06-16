<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — make business-key unique constraints unique *per entity* so a new
 * entity (e.g. CBTL) can reuse codes that already exist for Nonos.
 *
 * Each entry: [table, existing unique index name, columns for the new composite
 * unique (entity_id is prepended)]. Tables whose unique key already includes a
 * branch column (adoption_rate_tracking_remarks, month_end_count_items) are
 * intentionally left alone — a branch belongs to exactly one entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->map() as [$table, $oldIndex, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($oldIndex, $cols) {
                $t->dropUnique($oldIndex);
                $t->unique(array_merge(['entity_id'], $cols));
            });
        }
    }

    public function down(): void
    {
        foreach ($this->map() as [$table, $oldIndex, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($oldIndex, $cols) {
                $t->dropUnique(array_merge(['entity_id'], $cols));
                $t->unique($cols, $oldIndex);
            });
        }
    }

    private function map(): array
    {
        return [
            ['cost_centers', 'cost_centers_name_unique', ['name']],
            ['delivery_receipts', 'delivery_receipts_delivery_receipt_number_unique', ['delivery_receipt_number']],
            ['dts_mass_order_batches', 'dts_mass_order_batches_batch_number_unique', ['batch_number']],
            ['menus', 'menus_product_id_unique', ['product_id']],
            ['month_end_schedules', 'month_end_schedules_year_month_unique', ['year', 'month']],
            ['pos_masterfiles', 'pos_masterfiles_itemcode_unique', ['POSCode']],
            // NOTE: product_inventories.inventory_code is intentionally left
            // globally unique — it is the target of a foreign key, so making it
            // per-entity would require refactoring that FK. Revisit if a second
            // entity needs to reuse inventory codes.
            ['sales_budgets', 'sales_budget_unique', ['type', 'year', 'store_code']],
            ['store_orders', 'store_orders_order_number_unique', ['order_number']],
            // NOTE: suppliers.supplier_code is also a foreign-key target, so it
            // stays globally unique for the same reason as inventory_code above.
            ['wips', 'wips_sap_code_unique', ['sap_code']],
        ];
    }
};
