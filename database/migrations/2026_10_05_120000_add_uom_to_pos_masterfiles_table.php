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
        Schema::table('pos_masterfiles', function (Blueprint $table) {
            // The unit the POS item itself is measured in (a sub-prep kept in Gm, a drink sold per Cup).
            $table->string('UOM', 50)->nullable()->after('SubCategory');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_masterfiles', function (Blueprint $table) {
            $table->dropColumn('UOM');
        });
    }
};
