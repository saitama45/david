<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a sale's or a wastage's ingredient lines were frozen into bom_consumptions - set even
     * when it used none (a non-inventory product), so the reports never read today's BOM for it
     * again. Null: posted before lines were kept and not yet frozen by bom:freeze-history.
     */
    public function up(): void
    {
        foreach (['store_transactions', 'wastages'] as $table) {
            if (! Schema::hasColumn($table, 'bom_frozen_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dateTime('bom_frozen_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Forward-only: the column records which sales and wastages are frozen.
    }
};
