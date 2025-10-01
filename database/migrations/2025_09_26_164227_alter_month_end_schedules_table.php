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
        Schema::table('month_end_schedules', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['branch_id']);
            // Drop the column
            $table->dropColumn('branch_id');

            // Rename scheduled_date to calculated_date
            $table->renameColumn('scheduled_date', 'calculated_date');

            // Add new columns
            $table->unsignedSmallInteger('year')->nullable()->after('id');
            $table->unsignedTinyInteger('month')->nullable()->after('year'); // 1-12

            // Add unique constraint to prevent duplicate schedules for a given month/year
            $table->unique(['year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('month_end_schedules', function (Blueprint $table) {
            // Drop unique constraint
            $table->dropUnique(['year', 'month']);

            // Drop new columns
            $table->dropColumn('month');
            $table->dropColumn('year');

            // Rename calculated_date back to scheduled_date
            $table->renameColumn('calculated_date', 'scheduled_date');

            // Add branch_id back (nullable for now, or handle default)
            // This would require a default value or making it nullable if there are existing records.
            // For simplicity in reverse, we'll add it back as nullable.
            $table->foreignId('branch_id')->nullable()->constrained('store_branches')->onDelete('cascade');
        });
    }
};