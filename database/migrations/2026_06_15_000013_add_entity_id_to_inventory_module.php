<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Inventory module entity scoping.
 * Branch-owned stock tables backfill from their branch; product-linked tables
 * backfill from their product_inventory; pure reference tables default to Nonos.
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

        // Branch-owned.
        foreach (['product_inventory_stocks', 'product_inventory_stock_managers'] as $table) {
            DB::statement("UPDATE x SET x.entity_id = sb.entity_id FROM {$table} x INNER JOIN store_branches sb ON sb.id = x.store_branch_id WHERE x.entity_id IS NULL");
        }

        // Product-linked: derive from the parent product_inventory.
        foreach (['product_inventory_categories', 'product_inventory_cost_histories', 'product_inventory_histories'] as $table) {
            DB::statement("UPDATE x SET x.entity_id = p.entity_id FROM {$table} x INNER JOIN product_inventories p ON p.id = x.product_inventory_id WHERE x.entity_id IS NULL");
        }

        // Reference + any orphaned rows default to Nonos.
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
            'product_inventory_stocks',
            'product_inventory_stock_managers',
            'product_inventories',
            'product_inventory_categories',
            'inventory_categories',
            'product_categories',
            'product_inventory_cost_histories',
            'product_inventory_histories',
        ];
    }
};
