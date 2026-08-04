<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the UoM the order was originally placed in, so the supplier catalog
     * (`supplier_items`, which is keyed by ItemCode + SupplierCode + uom) can still
     * be matched after a commit-phase UoM change. Without it, changing the UoM
     * orphans the row from its category, classification and sort order.
     *
     * NULL means "never changed" — the join falls back to `uom`.
     */
    public function up(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->string('original_uom', 255)->nullable()->after('uom');
        });
    }

    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropColumn('original_uom');
        });
    }
};
