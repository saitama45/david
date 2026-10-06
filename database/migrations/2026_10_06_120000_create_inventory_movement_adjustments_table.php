<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Adjustment column of the Inventory Movement Report: a quantity entered against
     * an item's Variance, with the reason for it.
     *
     * One row per store, item and month. The month is the one whose count the report reads
     * as Actual MEC (the To Date's), so the adjustment stays with that count while the
     * dates are moved inside the month. It posts no stock.
     */
    public function up(): void
    {
        Schema::create('inventory_movement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->string('item_code');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // Signed, in the unit the report shows the item in (kept beside it for reference).
            $table->decimal('quantity', 18, 6);
            $table->string('uom')->nullable();
            $table->string('reason', 500);
            $table->foreignId('adjusted_by')->constrained('users');

            $table->timestamps();

            $table->unique(['store_branch_id', 'item_code', 'year', 'month'], 'inventory_movement_adjustments_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_adjustments');
    }
};
