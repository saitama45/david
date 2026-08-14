<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new HQ Sales Report adds a "Take Out" column (column Q): "Y" means the
     * line was taken out, blank means dine in. The flag is per line item, not per
     * receipt — the same receipt can hold both, and take-out lines are often
     * priced differently — so it lives on store_transaction_items.
     *
     * Existing rows predate the column and are all treated as dine in (false).
     */
    public function up(): void
    {
        Schema::table('store_transaction_items', function (Blueprint $table) {
            $table->boolean('take_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('store_transaction_items', function (Blueprint $table) {
            $table->dropColumn('take_out');
        });
    }
};
