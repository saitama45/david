<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Level 1 approver sending a branch's Month End Count back to the store.
     *
     * Kept apart from month_end_count_items because the re-upload replaces the
     * rejected rows; this row is what remains of who rejected it, when and why.
     */
    public function up(): void
    {
        Schema::create('month_end_count_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('month_end_schedule_id')->constrained('month_end_schedules')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('store_branches')->cascadeOnDelete();

            $table->string('reason', 1000);
            $table->unsignedInteger('item_count');
            $table->foreignId('rejected_by')->constrained('users');

            $table->timestamps();

            $table->index(['month_end_schedule_id', 'branch_id'], 'mec_rejection_schedule_branch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_end_count_rejections');
    }
};
