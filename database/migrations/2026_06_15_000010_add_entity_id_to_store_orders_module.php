<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Store Orders module entity scoping.
 *
 * Adds entity_id to store_orders and its child tables, then backfills:
 *  - store_orders        <- from its store_branch's entity
 *  - children            <- from their parent row's entity
 * dts_mass_order_batches has no branch/parent FK, so it defaults to Nonos
 * (the only entity that currently owns data).
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

        // Parent: derive entity from the branch.
        DB::statement('
            UPDATE so SET so.entity_id = sb.entity_id
            FROM store_orders so
            INNER JOIN store_branches sb ON sb.id = so.store_branch_id
            WHERE so.entity_id IS NULL
        ');

        // Children: derive entity from their parent.
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM store_order_items c INNER JOIN store_orders p ON p.id = c.store_order_id WHERE c.entity_id IS NULL');
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM store_order_remarks c INNER JOIN store_orders p ON p.id = c.store_order_id WHERE c.entity_id IS NULL');
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM delivery_receipts c INNER JOIN store_orders p ON p.id = c.store_order_id WHERE c.entity_id IS NULL');
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM order_item_remarks c INNER JOIN store_order_items p ON p.id = c.store_order_item_id WHERE c.entity_id IS NULL');
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM ordered_item_receive_dates c INNER JOIN store_order_items p ON p.id = c.store_order_item_id WHERE c.entity_id IS NULL');

        // Fallback for any rows orphaned from their parent, plus the batch table.
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
            'store_orders',
            'store_order_items',
            'store_order_remarks',
            'order_item_remarks',
            'ordered_item_receive_dates',
            'delivery_receipts',
            'dts_mass_order_batches',
        ];
    }
};
