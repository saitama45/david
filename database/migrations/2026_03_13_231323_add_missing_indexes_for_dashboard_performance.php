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
        Schema::table('product_inventory_stock_managers', function (Blueprint $table) {
            $table->index('store_branch_id', 'idx_pism_store_branch_id');
            $table->index('product_inventory_id', 'idx_pism_product_inventory_id');
            $table->index('transaction_date', 'idx_pism_transaction_date');
        });

        Schema::table('store_transactions', function (Blueprint $table) {
            $table->index('store_branch_id', 'idx_st_store_branch_id');
            $table->index('order_date', 'idx_st_order_date');
        });

        Schema::table('store_transaction_items', function (Blueprint $table) {
            $table->index('store_transaction_id', 'idx_sti_store_transaction_id');
        });

        Schema::table('supplier_items', function (Blueprint $table) {
            $table->index('ItemCode', 'idx_si_item_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory_stock_managers', function (Blueprint $table) {
            $table->dropIndex('idx_pism_store_branch_id');
            $table->dropIndex('idx_pism_product_inventory_id');
            $table->dropIndex('idx_pism_transaction_date');
        });

        Schema::table('store_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_st_store_branch_id');
            $table->dropIndex('idx_st_order_date');
        });

        Schema::table('store_transaction_items', function (Blueprint $table) {
            $table->dropIndex('idx_sti_store_transaction_id');
        });

        Schema::table('supplier_items', function (Blueprint $table) {
            $table->dropIndex('idx_si_item_code');
        });
    }
};
