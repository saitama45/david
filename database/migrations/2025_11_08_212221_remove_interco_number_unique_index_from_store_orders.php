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
        Schema::table('store_orders', function (Blueprint $table) {
            // Drop the unique index on interco_number column
            $table->dropUnique('store_orders_interco_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            // Recreate the unique index on interco_number column
            $table->unique('interco_number', 'store_orders_interco_number_unique');
        });
    }
};
