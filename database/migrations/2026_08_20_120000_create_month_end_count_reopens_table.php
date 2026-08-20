<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Targeted reopening of a closed Month End Count upload window.
     *
     * One row grants a single branch permission to upload for a single schedule
     * until `reopened_until`, without moving the schedule's count date (which
     * would reopen the month for every outstanding branch and rewrite the count
     * date of record).
     */
    public function up(): void
    {
        Schema::create('month_end_count_reopens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('month_end_schedule_id')->constrained('month_end_schedules')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('store_branches')->cascadeOnDelete();

            // Upload stays open for this branch/schedule until this moment.
            $table->dateTime('reopened_until');
            $table->foreignId('reopened_by')->constrained('users');

            $table->timestamps();

            // One standing reopen per branch per schedule; re-issuing extends it.
            $table->unique(['month_end_schedule_id', 'branch_id'], 'mec_reopen_schedule_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_end_count_reopens');
    }
};
