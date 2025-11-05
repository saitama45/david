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
        Schema::table('product_inventory_stocks', function (Blueprint $table) {
            // Add indexes for faster query performance
            $table->index('store_branch_id', 'idx_store_branch_id');
            $table->index('product_inventory_id', 'idx_product_inventory_id');
            $table->index(['store_branch_id', 'product_inventory_id'], 'idx_store_branch_product');
            $table->index('quantity', 'idx_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory_stocks', function (Blueprint $table) {
            // Drop the indexes
            $table->dropIndex('idx_store_branch_id');
            $table->dropIndex('idx_product_inventory_id');
            $table->dropIndex('idx_store_branch_product');
            $table->dropIndex('idx_quantity');
        });
    }
};
