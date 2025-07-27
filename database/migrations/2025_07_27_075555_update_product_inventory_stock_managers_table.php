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
            // 1. Drop the old foreign key constraint
            // The constraint name is typically 'table_name_column_name_foreign'.
            // If this name doesn't work, you might need to find the exact name from your database schema.
            // For example, by running `SHOW CREATE TABLE product_inventory_stock_managers;` in MySQL,
            // or inspecting in SQL Server Management Studio.
            $table->dropForeign(['product_inventory_id']);

            // 2. Add the new foreign key constraint pointing to sap_masterfiles
            // Ensure that the 'product_inventory_id' column is of the same type as 'id' in 'sap_masterfiles'
            // (e.g., unsignedBigInteger if 'id' in sap_masterfiles is an auto-incrementing big integer).
            $table->foreign('product_inventory_id')
                  ->references('id')
                  ->on('sap_masterfiles')
                  ->onDelete('cascade'); // Or 'set null' or 'restrict' based on your desired behavior
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory_stock_managers', function (Blueprint $table) {
            // Revert the foreign key in the reverse migration
            $table->dropForeign(['product_inventory_id']);

            // Optionally, re-add the old foreign key if you intend to rollback to a state
            // where 'product_inventories' is still the referenced table.
            // Be cautious if 'product_inventories' table is no longer valid or exists.
            // $table->foreign('product_inventory_id')
            //       ->references('id')
            //       ->on('product_inventories')
            //       ->onDelete('cascade');
        });
    }
};
