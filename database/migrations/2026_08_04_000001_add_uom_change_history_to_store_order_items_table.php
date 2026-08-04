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
            // Audit trail for UoM changes made during the CS Mass Commit phase.
            // Stores one entry per line: "[User_Name] changed UoM from [Original UoM] to [Changed UoM]"
            $table->text('uom_change_history')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_order_items', function (Blueprint $table) {
            $table->dropColumn('uom_change_history');
        });
    }
};
