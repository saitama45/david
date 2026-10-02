<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Final Receive All" on /orders-receiving/show posts every unconfirmed receipt and locks
     * the order's item list. It replaced the 3-day correction window: until an order is
     * finalized its items stay editable, and once it is they never are.
     */
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'receiving_finalized_at')) {
                $table->dateTime('receiving_finalized_at')->nullable();
            }

            if (! Schema::hasColumn('store_orders', 'receiving_finalized_by')) {
                $table->unsignedBigInteger('receiving_finalized_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only: dropping the columns would unlock every finalized order.
    }
};
