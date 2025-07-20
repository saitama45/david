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
            // CRITICAL FIX: Drop the specific foreign key constraint before renaming/changing type
            // The error message indicates the foreign key name is 'store_order_items_product_inventory_id_foreign'
            $table->dropForeign('store_order_items_product_inventory_id_foreign');

            // 1. Rename the column from 'product_inventory_id' to 'item_code'
            $table->renameColumn('product_inventory_id', 'item_code');
        });

        Schema::table('store_order_items', function (Blueprint $table) {
            // 2. Change the column type of 'item_code' to string (NVARCHAR for SQL Server)
            // Ensure the length (e.g., 255) is sufficient for your ItemCodes
            $table->string('item_code', 255)->change();

            // IMPORTANT: Since 'ItemCode' in 'supplier_items' is NOT unique,
            // a direct foreign key constraint from 'store_order_items.item_code'
            // to 'supplier_items.ItemCode' CANNOT be established in the database.
            // The relationship will be managed at the application (Eloquent) level.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            // Revert column type first (e.g., to bigint if it was originally)
            // If you changed it from bigint, you need to revert it before renaming back.
            // $table->bigInteger('item_code')->change(); // Uncomment and adjust if needed

            // Rename back to original 'product_inventory_id'
            $table->renameColumn('item_code', 'product_inventory_id');

            // Re-add the foreign key if it existed and you want to revert to the original state
            // This assumes the original table it referenced (e.g., product_inventories) still exists
            // and product_inventory_id was a foreign key to its 'id' column.
            // If your original foreign key was to a different table or column, adjust accordingly.
            // $table->foreign('product_inventory_id')->references('id')->on('product_inventories');
        });
    }
};
