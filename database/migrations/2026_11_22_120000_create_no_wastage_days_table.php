<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "No Wastage for the Day": a store states it had no wastage on a date, so accounting does not
     * chase a wastage record that will never come. One row per store and date. A marked day takes no
     * wastage record; a day marked by mistake is unmarked (withdrawn_*). A row is never deleted, and
     * marking the day again reuses it.
     */
    public function up(): void
    {
        if (Schema::hasTable('no_wastage_days')) {
            return;
        }

        Schema::create('no_wastage_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('store_branch_id');
            $table->date('wastage_date');
            $table->unsignedBigInteger('declared_by');
            $table->dateTime('declared_at');
            $table->dateTime('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();
            $table->timestamps();

            $table->unique(['store_branch_id', 'wastage_date']);
            $table->index(['entity_id', 'wastage_date']);
        });
    }

    public function down(): void
    {
        // Forward-only: these rows are what accounting monitored.
    }
};
