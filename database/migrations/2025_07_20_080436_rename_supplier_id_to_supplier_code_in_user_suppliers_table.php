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
        Schema::table('user_suppliers', function (Blueprint $table) {
            // 1. Drop the existing foreign key constraint
            // This is the constraint that was causing the dependency error.
            $table->dropForeign('user_suppliers_supplier_id_foreign');

            // 2. Drop the existing composite primary key constraint
            // The name 'user_suppliers_user_id_supplier_id_primary' is inferred from your error message.
            // If this name is different in your database, you'll need to adjust it.
            $table->dropPrimary(['user_id', 'supplier_id']);

            // 3. Rename the column
            $table->renameColumn('supplier_id', 'supplier_code');
        });

        Schema::table('user_suppliers', function (Blueprint $table) {
            // 4. Change the column type to string (nvarchar(255))
            // This assumes supplier_code in your suppliers table is a string.
            $table->string('supplier_code')->change();

            // 5. Re-add the primary key on the new columns
            $table->primary(['user_id', 'supplier_code'], 'user_suppliers_user_id_supplier_code_primary');

            // 6. Re-add the foreign key constraint, referencing supplier_code on the suppliers table
            // Ensure that `supplier_code` in the `suppliers` table is unique and indexed.
            $table->foreign('supplier_code')->references('supplier_code')->on('suppliers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_suppliers', function (Blueprint $table) {
            // 1. Drop the foreign key constraint added in up()
            $table->dropForeign(['supplier_code']);

            // 2. Drop the primary key on the new column name
            $table->dropPrimary(['user_id', 'supplier_code']);

            // 3. Rename the column back to its original name
            $table->renameColumn('supplier_code', 'supplier_id');
        });

        Schema::table('user_suppliers', function (Blueprint $table) {
            // 4. Change the column type back to its original (e.g., integer)
            // Adjust to your original type if it was different from integer
            $table->integer('supplier_id')->change();

            // 5. Re-add the original primary key
            $table->primary(['user_id', 'supplier_id'], 'user_suppliers_user_id_supplier_id_primary');

            // 6. Re-add the original foreign key
            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
        });
    }
};
