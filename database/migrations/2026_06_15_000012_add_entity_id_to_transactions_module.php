<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Transactions / Usage / Wastage / Cash module entity scoping.
 * Branch-owned tables backfill from their store_branch's entity; child tables
 * backfill from their parent row.
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

        // Branch-owned: derive entity from the branch.
        foreach ([
            'store_transactions',
            'usage_records',
            'wastages',
            'cash_pull_outs',
            'purchase_item_batches',
            'adoption_rate_tracking_remarks',
        ] as $table) {
            DB::statement("
                UPDATE x SET x.entity_id = sb.entity_id
                FROM {$table} x
                INNER JOIN store_branches sb ON sb.id = x.store_branch_id
                WHERE x.entity_id IS NULL
            ");
        }

        // Children: derive entity from their parent row.
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM store_transaction_items c INNER JOIN store_transactions p ON p.id = c.store_transaction_id WHERE c.entity_id IS NULL');
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM usage_record_items c INNER JOIN usage_records p ON p.id = c.usage_record_id WHERE c.entity_id IS NULL');
        DB::statement('UPDATE c SET c.entity_id = p.entity_id FROM cash_pull_out_items c INNER JOIN cash_pull_outs p ON p.id = c.cash_pull_out_id WHERE c.entity_id IS NULL');

        // Fallback for any orphaned rows.
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
            'store_transactions',
            'store_transaction_items',
            'usage_records',
            'usage_record_items',
            'wastages',
            'cash_pull_outs',
            'cash_pull_out_items',
            'purchase_item_batches',
            'adoption_rate_tracking_remarks',
        ];
    }
};
