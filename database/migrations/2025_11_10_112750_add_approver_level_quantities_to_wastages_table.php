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
            $table->decimal('approverlvl1_qty', 10, 2)->nullable()->after('wastage_qty');
            $table->decimal('approverlvl2_qty', 10, 2)->nullable()->after('approverlvl1_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wastages', function (Blueprint $table) {
            $table->dropColumn(['approverlvl1_qty', 'approverlvl2_qty']);
        });
    }
};
