<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an Item Type's items count in the totals of the Qty / Cost Variance report. Finance
 * reads those totals without SMALLWARES and CAPEX, while the stores still see those items on
 * the list, so a type can stay listed and be left out of the sum. Every existing type counts,
 * which is what the report has always done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_item_types', function (Blueprint $table) {
            $table->boolean('counts_in_totals')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sap_item_types', function (Blueprint $table) {
            $table->dropColumn('counts_in_totals');
        });
    }
};
