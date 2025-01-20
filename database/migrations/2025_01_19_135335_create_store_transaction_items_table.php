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
        Schema::create('store_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_transaction_id')->constrained('store_transactions');
            $table->string('product_id');
            $table->foreign('product_id')->references('product_id')->on('menus');
            $table->integer('base_quantity');
            $table->integer('quantity');
            $table->double('price');
            $table->double('discount')->default(0);
            $table->double('line_total');
            $table->double('net_total');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_transaction_items');
    }
};
