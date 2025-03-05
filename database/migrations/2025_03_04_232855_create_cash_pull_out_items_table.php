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
        Schema::create('cash_pull_out_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_pull_out_id')->constrained();
            $table->foreignId('product_inventory_id')->constrained();
            $table->integer('quantity_ordered');
            $table->integer('quantity_approved')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_pull_out_items');
    }
};
