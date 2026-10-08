<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A store's explanation of the transactions it still had unfinished after the MEC
     * Scheduled Date of a count it owes: one report per store and count.
     *
     * The row is opened when the system first finds those pendings (required_at) and holds
     * what was found; filing adds a reason to each, and the report is final from then on.
     */
    public function up(): void
    {
        Schema::create('month_end_count_incident_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('month_end_schedule_id')->constrained('month_end_schedules')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('store_branches')->cascadeOnDelete();

            $table->dateTime('required_at');
            // [{key, label, count}] while open; filing adds reason and open (still pending then).
            $table->json('pendings');

            $table->string('action_taken', 2000)->nullable();
            $table->date('target_date')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users');
            $table->dateTime('filed_at')->nullable();

            $table->timestamps();

            $table->unique(['month_end_schedule_id', 'branch_id'], 'mec_incident_report_schedule_branch_unique');
        });

        // On by default (the office's choice); the Configuration tab turns it off per entity.
        Schema::table('month_end_count_settings', function (Blueprint $table) {
            $table->boolean('incident_report_required')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('month_end_count_settings', function (Blueprint $table) {
            $table->dropColumn('incident_report_required');
        });

        Schema::dropIfExists('month_end_count_incident_reports');
    }
};
