<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('month_end_count_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->unique()->constrained('entities')->cascadeOnDelete();

            // Download window: how long before the MEC Schedule Date the template can be downloaded.
            $table->unsignedSmallInteger('download_lead_days')->default(3);
            $table->string('download_lead_unit')->default('business'); // business | calendar

            // Whether download/upload actions are blocked entirely on weekends.
            $table->boolean('block_on_weekends')->default(true);

            // Upload window start: how long after the MEC Schedule Date uploading opens.
            $table->unsignedSmallInteger('upload_start_days')->default(1);
            $table->string('upload_start_unit')->default('calendar'); // business | calendar

            // Upload cutoff: end of the upload window (date offset after MEC Schedule Date + time-of-day).
            $table->boolean('upload_cutoff_enabled')->default(true);
            $table->unsignedSmallInteger('upload_cutoff_days')->nullable()->default(2);
            $table->string('upload_cutoff_unit')->default('calendar'); // business | calendar
            $table->time('upload_cutoff_time')->nullable()->default('23:59:00');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_end_count_settings');
    }
};
