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
            $table->foreignId('encoder_id')->constrained('users');
            $table->string('order_number');
            $table->string('transaction_period');
            $table->date('transaction_date');
            $table->string('cashier_id');
            $table->string('order_type');
            $table->decimal('sub_total', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->string('payment_type');
            $table->decimal('discount_amount', 10, 2);
            $table->string('discount_type');
            $table->decimal('service_charge', 10, 2);
            $table->string('remarks')->nullable();
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
