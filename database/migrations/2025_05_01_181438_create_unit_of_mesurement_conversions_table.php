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
        Schema::create('unit_of_mesurement_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_code')->constrained('product_inventories')->onDelete('cascade');
            $table->string('uom_group')->nullable();
            $table->decimal('alternative_quantity')->nullable();
            $table->decimal('base_quantity')->nullable();
            $table->string('alternative_uom')->nullable();
            $table->string('base_uom')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unit_of_mesurement_conversions');
    }
};
