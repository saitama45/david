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
        Schema::create('wip_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wip_id')->constrained('wips')->cascadeOnDelete();
            $table->foreignId('product_inventory_id')->constrained('product_inventories')->cascadeOnDelete();
            $table->double('quantity');
            $table->string('unit')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wip_ingredients');
    }
};
