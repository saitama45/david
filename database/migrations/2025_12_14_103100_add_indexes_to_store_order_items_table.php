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
        Schema::table('store_order_items', function (Blueprint $table) {
            // Add index for item_code for faster joins with supplier_items
            $table->index('item_code', 'idx_store_order_items_item_code');
            
            // Add index for store_order_id for faster joins
            $table->index('store_order_id', 'idx_store_order_items_store_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropIndex('idx_store_order_items_item_code');
            $table->dropIndex('idx_store_order_items_store_order_id');
        });
    }
};