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
        Schema::create('product_inventories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_category_id')->constrained('inventory_categories')->cascadeOnDelete();

            $table->foreignId('unit_of_measurement_id')->constrained('unit_of_measurements')->cascadeOnDelete();

            $table->string('name');

            $table->string('inventory_code')->unique();

            $table->string('brand')->nullable();

            $table->double('conversion');

            $table->double('cost');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_inventories');
    }
};
