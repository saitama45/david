<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // store_transactions: speeds up date-range + branch filters in sales queries
        if (!$this->indexExists('store_transactions', 'idx_st_branch_date')) {
            Schema::table('store_transactions', function (Blueprint $table) {
                $table->index(['store_branch_id', 'order_date'], 'idx_st_branch_date');
            });
        }

        // store_transaction_items: speeds up joins from sales aggregate queries
        if (!$this->indexExists('store_transaction_items', 'idx_sti_transaction_id')) {
            Schema::table('store_transaction_items', function (Blueprint $table) {
                $table->index(['store_transaction_id'], 'idx_sti_transaction_id');
            });
        }

        // product_inventory_stock_managers: speeds up COGS and inventory sum queries
        if (!$this->indexExists('product_inventory_stock_managers', 'idx_pism_branch_date')) {
            Schema::table('product_inventory_stock_managers', function (Blueprint $table) {
                $table->index(['store_branch_id', 'transaction_date'], 'idx_pism_branch_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('store_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_st_branch_date');
        });

        Schema::table('store_transaction_items', function (Blueprint $table) {
            $table->dropIndex('idx_sti_transaction_id');
        });

        Schema::table('product_inventory_stock_managers', function (Blueprint $table) {
            $table->dropIndex('idx_pism_branch_date');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            return DB::selectOne(
                "SELECT 1 FROM sys.indexes WHERE name = ? AND object_id = OBJECT_ID(?)",
                [$indexName, $table]
            ) !== null;
        }

        // MySQL / MariaDB fallback
        return DB::selectOne(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        ) !== null;
    }
};
