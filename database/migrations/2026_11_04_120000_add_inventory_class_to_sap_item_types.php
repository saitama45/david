<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Inventory Class of an Item Type: INVENTORIABLE or NON-INVENTORIABLE, set per type on
 * /sapitems-list/types. It decides which list the Inventory Movement and Qty / Cost Variance
 * reports show the type's items on. Every existing type starts INVENTORIABLE, so no item
 * leaves those reports' default list until someone marks its type otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_item_types', function (Blueprint $table) {
            $table->string('inventory_class', 30)->default('INVENTORIABLE');
        });
    }

    public function down(): void
    {
        Schema::table('sap_item_types', function (Blueprint $table) {
            $table->dropColumn('inventory_class');
        });
    }
};
