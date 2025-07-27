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
        Schema::table('purchase_item_batches', function (Blueprint $table) {
            // 1. Drop the old foreign key constraint
            // The constraint name might vary. You can find it by inspecting your database schema
            // (e.g., using a database client like DBeaver, SQL Server Management Studio, or by running
            // `SHOW CREATE TABLE purchase_item_batches;` in MySQL or equivalent for SQL Server).
            // A common Laravel-generated name is 'table_name_column_name_foreign'.
            // If the name below doesn't work, replace it with the actual constraint name from your database.
            $table->dropForeign(['product_inventory_id']); // This drops the constraint by column name

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
        Schema::table('purchase_item_batches', function (Blueprint $table) {
            // Revert the foreign key in the reverse migration
            $table->dropForeign(['product_inventory_id']);

            // Re-add the old foreign key if necessary for rollback,
            // but be cautious if the 'product_inventories' table is truly being deprecated.
            // If 'product_inventories' is gone, this part might cause issues on rollback.
            // For a clean rollback, you might consider if re-adding the old FK is truly desired.
            // For now, we'll assume you might want to revert to the old FK if the table still exists.
            // $table->foreign('product_inventory_id')
            //       ->references('id')
            //       ->on('product_inventories')
            //       ->onDelete('cascade');
        });
    }
};
