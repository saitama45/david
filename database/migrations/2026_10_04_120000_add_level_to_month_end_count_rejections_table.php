<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which approval level returned the count. Level 2 can reject too now; every
     * rejection recorded before this was a Level 1 one, hence the default.
     */
    public function up(): void
    {
        Schema::table('month_end_count_rejections', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->default(1)->after('item_count');
        });
    }

    public function down(): void
    {
        Schema::table('month_end_count_rejections', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
