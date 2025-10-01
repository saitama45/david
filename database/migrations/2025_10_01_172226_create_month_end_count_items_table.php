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
        Schema::create('month_end_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('month_end_schedule_id')->constrained('month_end_schedules')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('store_branches')->onDelete('cascade');
            $table->foreignId('sap_masterfile_id')->constrained('sap_masterfiles')->onDelete('cascade');
            $table->string('item_code');
            $table->string('item_name');
            $table->string('packaging_config')->nullable();
            $table->string('config')->nullable();
            $table->string('uom');
            $table->decimal('bulk_qty', 10, 4)->nullable();
            $table->decimal('loose_qty', 10, 4)->nullable();
            $table->string('loose_uom')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('total_qty', 10, 4); // The counted SOH

            // Approval fields
            $table->foreignId('level1_approved_by')->nullable()->constrained('users');
            $table->timestamp('level1_approved_at')->nullable();
            $table->foreignId('level2_approved_by')->nullable()->constrained('users');
            $table->timestamp('level2_approved_at')->nullable();
            $table->string('status')->default('uploaded'); // uploaded, level1_approved, level2_approved, rejected, expired

            $table->foreignId('created_by')->constrained('users'); // User who uploaded
            $table->timestamps();

            // Add unique constraint to prevent duplicate items for a given schedule/branch
            $table->unique(['month_end_schedule_id', 'branch_id', 'sap_masterfile_id'], 'unique_mec_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('month_end_count_items');
    }
};