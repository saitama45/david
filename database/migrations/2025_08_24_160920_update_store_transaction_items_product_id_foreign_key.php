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
        Schema::table('store_transaction_items', function (Blueprint $table) {
            // Check if the 'product_id' column exists before attempting to modify it.
            if (Schema::hasColumn('store_transaction_items', 'product_id')) {
                // Explicitly drop the foreign key constraint by its exact name.
                // This name is derived from previous error messages.
                // This needs to be done before dropping the column.
                // If this constraint name is not found, you might need to adjust it
                // based on your database's actual constraint name.
                $table->dropForeign('store_transaction_items_product_id_foreign');

                // Now that the foreign key is dropped, we can safely drop the column.
                $table->dropColumn('product_id');
            }

            // Re-add the 'product_id' column as unsignedBigInteger
            // to match the 'id' column (primary key) of 'pos_masterfiles'.
            // Placed after 'store_transaction_id' for logical order.
            $table->unsignedBigInteger('product_id')->after('store_transaction_id');

            // Add the new foreign key constraint referencing the 'pos_masterfiles' table.
            // --- CRITICAL FIX START ---
            // Changed 'restrict' to 'no action' for SQL Server compatibility.
            $table->foreign('product_id')
                  ->references('id')
                  ->on('pos_masterfiles')
                  ->onDelete('no action'); // 'NO ACTION' is the SQL Server equivalent of 'RESTRICT'
            // --- CRITICAL FIX END ---
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_transaction_items', function (Blueprint $table) {
            // Drop the new foreign key constraint first.
            $table->dropForeign(['product_id']);

            // Drop the 'product_id' column that was added in the 'up' method.
            $table->dropColumn('product_id');

            // Re-add the original 'product_id' column as a string,
            // assuming its original type was a string (like a POSCode) before this change.
            // Adjust the length if your original 'product_id' string had a different max length.
            $table->string('product_id')->nullable()->after('store_transaction_id');
        });
    }
};
