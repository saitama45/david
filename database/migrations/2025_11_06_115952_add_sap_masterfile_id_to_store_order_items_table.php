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
            $table->unsignedBigInteger('sap_masterfile_id')->nullable()->after('id');
            $table->foreign('sap_masterfile_id')->references('id')->on('sap_masterfiles')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropForeign(['sap_masterfile_id']);
            $table->dropColumn('sap_masterfile_id');
        });
    }
};
