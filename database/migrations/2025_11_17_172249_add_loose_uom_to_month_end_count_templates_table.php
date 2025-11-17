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
        Schema::table('month_end_count_templates', function (Blueprint $table) {
            $table->string('loose_uom')->nullable()->after('uom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('month_end_count_templates', function (Blueprint $table) {
            $table->dropColumn('loose_uom');
        });
    }
};
