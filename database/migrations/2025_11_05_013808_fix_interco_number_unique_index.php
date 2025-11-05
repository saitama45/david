<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, update existing NULL values to unique temporary values
        // This will allow the unique index to be created
        DB::statement('
            UPDATE store_orders
            SET interco_number = \'TEMP-\' + CAST(id AS VARCHAR) + \'-\' + REPLACE(CONVERT(VARCHAR, GETDATE(), 120), \':\', \'-\')
            WHERE interco_number IS NULL
        ');

        // Check if the unique index already exists and drop it if it does
        if (Schema::hasIndex('store_orders', 'store_orders_interco_number_unique')) {
            Schema::table('store_orders', function (Blueprint $table) {
                $table->dropUnique('store_orders_interco_number_unique');
            });
        }

        // Now create the unique index for future records
        Schema::table('store_orders', function (Blueprint $table) {
            $table->unique('interco_number', 'store_orders_interco_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            // Drop the unique index if it exists
            $table->dropUnique('store_orders_interco_number_unique');

            // Set the temporary values back to NULL
            DB::statement('
                UPDATE store_orders
                SET interco_number = NULL
                WHERE interco_number LIKE \'TEMP-%\'
            ');
        });
    }
};
