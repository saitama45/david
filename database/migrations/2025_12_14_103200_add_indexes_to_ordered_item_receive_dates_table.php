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
        Schema::table('ordered_item_receive_dates', function (Blueprint $table) {
            // Add index for store_order_item_id for faster joins
            $table->index('store_order_item_id', 'idx_ordered_item_receive_dates_store_order_item_id');
            
            // Add index for received_by_user_id for faster user lookups
            $table->index('received_by_user_id', 'idx_ordered_item_receive_dates_received_by_user_id');
            
            // Add index for created_at for ordering
            $table->index('created_at', 'idx_ordered_item_receive_dates_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ordered_item_receive_dates', function (Blueprint $table) {
            $table->dropIndex('idx_ordered_item_receive_dates_store_order_item_id');
            $table->dropIndex('idx_ordered_item_receive_dates_received_by_user_id');
            $table->dropIndex('idx_ordered_item_receive_dates_created_at');
        });
    }
};