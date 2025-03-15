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
        Schema::create('product_inventory_stock_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_item_batch_id')->constrained('purchase_item_batches')->cascadeOnDelete();
            $table->foreignId('product_inventory_id')->constrained('product_inventories')->cascadeOnDelete();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->float('quantity');
            $table->string('action');
            $table->double('unit_cost');
            $table->double('total_cost');
            $table->date('transaction_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_inventory_stock_useds');
    }
};
