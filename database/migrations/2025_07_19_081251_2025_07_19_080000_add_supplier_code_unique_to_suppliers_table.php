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
        Schema::table('suppliers', function (Blueprint $table) {
            // Add a unique index to supplier_code.
            // This is essential for it to be referenced by a foreign key.
            // If supplier_code is not already a string, you might need to change its type first.
            $table->string('supplier_code')->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Drop the unique index
            $table->dropUnique(['supplier_code']);
            // If you changed the column type in up(), revert it here if necessary.
            // For example: $table->string('supplier_code')->change(); // if it was already string
        });
    }
};
