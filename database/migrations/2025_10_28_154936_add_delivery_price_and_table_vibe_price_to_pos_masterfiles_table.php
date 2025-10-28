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
            $table->decimal('DeliveryPrice', 10, 4)->default(0)->after('SRP');
            $table->decimal('TableVibePrice', 10, 4)->default(0)->after('DeliveryPrice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_masterfiles', function (Blueprint $table) {
            $table->dropColumn(['DeliveryPrice', 'TableVibePrice']);
        });
    }
};
