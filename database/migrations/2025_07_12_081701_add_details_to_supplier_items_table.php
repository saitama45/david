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
        Schema::table('supplier_items', function (Blueprint $table) {
            // 1. Rename 'ItemNo' to 'ItemCode'
            $table->renameColumn('ItemNo', 'ItemCode');

            // 2. Add new columns with specified types and defaults
            $table->string('item_name')->default('');
            $table->string('category')->default('');
            $table->string('brand')->default('');
            $table->string('classification')->default('');
            $table->string('packaging_config')->default('');
            $table->decimal('config', 10, 2)->default(0.00); // Decimal with 2 decimal places, default 0.00
            $table->string('uom')->default('');
            $table->decimal('cost', 10, 2)->default(0.00); // Decimal for currency, default 0.00
            $table->decimal('srp', 10, 2)->default(0.00);  // Decimal for currency, default 0.00
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_items', function (Blueprint $table) {
            // 1. Reverse the column rename: 'ItemCode' back to 'ItemNo'
            $table->renameColumn('ItemCode', 'ItemNo');

            // 2. Drop the newly added columns
            $table->dropColumn([
                'item_name',
                'category',
                'brand',
                'classification',
                'packaging_config',
                'config',
                'uom',
                'cost',
                'srp',
            ]);
        });
    }
};