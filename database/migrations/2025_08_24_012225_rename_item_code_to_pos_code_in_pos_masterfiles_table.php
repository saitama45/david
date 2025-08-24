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
            // Check if the 'ItemCode' column exists before trying to rename it
            $table->renameColumn('ItemCode', 'POSCode');
            $table->renameColumn('ItemDescription', 'POSDescription');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_masterfiles', function (Blueprint $table) {
            $table->renameColumn('POSCode', 'ItemCode');
            $table->renameColumn('POSDescription', 'ItemDescription');
        });
    }
};

