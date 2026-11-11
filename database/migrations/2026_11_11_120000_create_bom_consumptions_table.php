<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each sale and each wasted Sub-Prep used of its raw materials, written when the
     * stock is posted. Reports read these lines instead of today's BOM, so a recipe changed
     * later never changes a past figure.
     *
     * qty is BOM Qty x units in the BOM unit, signed as the report sums it (a returned sale or
     * a negative BOM Qty is below 0); stock_qty is the same in the stock row's unit.
     */
    public function up(): void
    {
        if (Schema::hasTable('bom_consumptions')) {
            return;
        }

        Schema::create('bom_consumptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('store_branch_id');
            $table->date('business_date');
            $table->string('source_type', 20); // sale | wastage
            $table->unsignedBigInteger('store_transaction_id')->nullable();
            $table->unsignedBigInteger('store_transaction_item_id')->nullable();
            $table->unsignedBigInteger('wastage_id')->nullable();
            $table->unsignedBigInteger('pos_masterfile_id')->nullable();
            $table->string('pos_code')->nullable();
            $table->string('pos_description')->nullable();
            $table->unsignedBigInteger('bom_line_id')->nullable();
            $table->string('item_code');
            $table->string('bom_uom')->nullable();
            $table->decimal('bom_qty', 18, 7);
            $table->decimal('units', 18, 4);
            $table->decimal('qty', 28, 10);
            $table->unsignedBigInteger('stock_row_id')->nullable();
            $table->decimal('stock_qty', 28, 10)->nullable();
            $table->decimal('unit_cost', 28, 10)->nullable();
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->string('origin', 20); // posted | snapshot | current_bom
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_correction_id')->nullable();
            $table->timestamps();

            $table->index(['store_branch_id', 'source_type', 'business_date', 'item_code'], 'bom_consumptions_report');
            $table->index('store_transaction_id');
            $table->index('wastage_id');
        });
    }

    public function down(): void
    {
        // Forward-only: these lines are the record of what past sales and wastage used.
    }
};
