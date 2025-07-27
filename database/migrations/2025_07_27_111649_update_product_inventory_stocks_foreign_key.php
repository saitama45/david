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
            // 1. Drop the existing foreign key constraint
            // You might need to adjust the constraint name if it's different in your database.
            // Common naming conventions are 'table_column_foreign' or a hash.
            // You can find the exact name by inspecting your database schema or running:
            // SHOW CREATE TABLE product_inventory_stocks; (for MySQL)
            // SELECT constraint_name FROM information_schema.key_column_usage WHERE table_name = 'product_inventory_stocks' AND column_name = 'product_inventory_id' AND referenced_table_name = 'product_inventories'; (for SQL Server/PostgreSQL)
            // If you're unsure, you might need to try common patterns or look it up.
            // For now, let's assume the Laravel default naming convention.
            $table->dropForeign(['product_inventory_id']); // This drops the constraint by column name


            // 2. Add the new foreign key constraint referencing 'sap_masterfiles'
            $table->foreign('product_inventory_id')
                  ->references('id')
                  ->on('sap_masterfiles')
                  ->onDelete('cascade'); // Or 'restrict', 'set null' based on your desired behavior
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory_stocks', function (Blueprint $table) {
            // Revert: Drop the new foreign key constraint
            $table->dropForeign(['product_inventory_id']);

            // Re-add the old foreign key constraint (if needed for rollback purposes)
            // This assumes the 'product_inventories' table still exists and is relevant for rollback.
            $table->foreign('product_inventory_id')
                  ->references('id')
                  ->on('product_inventories')
                  ->onDelete('cascade'); // Match your original onDelete behavior
        });
    }
};
