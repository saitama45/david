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
            // Change to a higher precision decimal
            $table->decimal('quantity', 20, 10)->change(); // Example: 20 total digits, 10 after decimal
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_inventory_stock_managers', function (Blueprint $table) {
            // Revert to original precision if needed, or a sensible default
            $table->decimal('quantity', 10, 4)->change();
        });
    }
};