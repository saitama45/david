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
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('committed_by')->nullable()->after('cost_per_quantity');
            $table->datetime('committed_date')->nullable()->after('committed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropColumn(['committed_by', 'committed_date']);
        });
    }
};
