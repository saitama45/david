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
        Schema::create('purchase_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_pull_out_item_id')->nullable()->constrained('cash_pull_out_items')->cascadeOnDelete();
            $table->foreignId('store_order_item_id')->nullable()->constrained('store_order_items')->cascadeOnDelete();
            $table->foreignId('product_inventory_id')->constrained('product_inventories')->cascadeOnDelete();
            $table->date('purchase_date');
            $table->float('quantity');
            $table->double('unit_cost');
            $table->float('remaining_quantity');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_item_batches');
    }
};
