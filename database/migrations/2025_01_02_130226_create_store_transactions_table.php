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
        Schema::create('store_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_branch_id')->constrained('store_branches');
            $table->date('order_date');
            $table->string('posted');
            $table->string('tim_number');
            $table->string('receipt_number');
            $table->string('lot_serial')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('customer')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_transactions');
    }
};
