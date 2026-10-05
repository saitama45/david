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
        Schema::table('wastages', function (Blueprint $table) {
            // A wasted Sub-Prep is a POS item, not a SAP item: such a line has this and no
            // sap_masterfile_id. Its raw materials come from the item's BOM.
            $table->unsignedBigInteger('pos_masterfile_id')->nullable()->after('sap_masterfile_id');
            $table->foreign('pos_masterfile_id')->references('id')->on('pos_masterfiles')->onDelete('no action');
            $table->index('pos_masterfile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wastages', function (Blueprint $table) {
            $table->dropForeign(['pos_masterfile_id']);
            $table->dropIndex(['pos_masterfile_id']);
            $table->dropColumn('pos_masterfile_id');
        });
    }
};
