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
        Schema::table('sap_masterfiles', function (Blueprint $table) {
            // Add composite index for ItemCode and AltUOM for faster lookups
            $table->index(['ItemCode', 'AltUOM'], 'idx_itemcode_altuom');
            
            // Add individual index on AltUOM for case-insensitive searches
            $table->index('AltUOM', 'idx_altuom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sap_masterfiles', function (Blueprint $table) {
            $table->dropIndex('idx_itemcode_altuom');
            $table->dropIndex('idx_altuom');
        });
    }
};