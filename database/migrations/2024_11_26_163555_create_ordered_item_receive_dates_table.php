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
        Schema::create('ordered_item_receive_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_order_item_id')->constrained('store_order_items')->cascadeOnDelete();
            $table->foreignId('received_by_user_id')->constrained('users');
            $table->foreignId('approval_action_by')->nullable()->constrained('users');
            $table->integer('quantity_received');
            $table->dateTime('received_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ordered_item_receive_dates');
    }
};
