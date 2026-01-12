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
        Schema::table('ordered_item_receive_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by_user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ordered_item_receive_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by_user_id')->nullable(false)->change();
        });
    }
};