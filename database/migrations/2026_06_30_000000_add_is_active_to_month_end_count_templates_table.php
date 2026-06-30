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
            $table->boolean('is_active')->default(1)->after('loose_uom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('month_end_count_templates', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
