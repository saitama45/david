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
        Schema::create('wastages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_branch_id')->constrained('store_branches');
            $table->enum('wastage_status', ['PENDING', 'APPROVED_LVL1', 'APPROVED_LVL2', 'CANCELLED'])->default('PENDING');
            $table->string('wastage_no')->unique();
            $table->foreignId('sap_masterfile_id')->nullable()->constrained('sap_masterfiles');
            $table->decimal('wastage_qty', 10, 2);
            $table->decimal('cost', 10, 2);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('approved_level1_by')->nullable()->constrained('users');
            $table->dateTime('approved_level1_date')->nullable();
            $table->foreignId('approved_level2_by')->nullable()->constrained('users');
            $table->dateTime('approved_level2_date')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->dateTime('cancelled_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wastages');
    }
};
