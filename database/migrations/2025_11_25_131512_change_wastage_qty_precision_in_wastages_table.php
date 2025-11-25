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
            $table->decimal('wastage_qty', 10, 3)->change();
            $table->decimal('approverlvl1_qty', 10, 3)->nullable()->change();
            $table->decimal('approverlvl2_qty', 10, 3)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wastages', function (Blueprint $table) {
            $table->decimal('wastage_qty', 8, 2)->change();
            $table->decimal('approverlvl1_qty', 8, 2)->nullable()->change();
            $table->decimal('approverlvl2_qty', 8, 2)->nullable()->change();
        });
    }
};