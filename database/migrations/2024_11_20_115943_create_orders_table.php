<?php

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\Branch;
use App\Models\Supplier;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encoder_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignIdFor(Supplier::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('received_by_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('store_branch_id')
                ->constrained('store_branches')
                ->cascadeOnDelete();

            $table->foreignId('approved_by_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('order_number');

            $table->enum('order_status', OrderStatus::values())->default(OrderStatus::PENDING->value);

            $table->enum('order_request_status', OrderRequestStatus::values())->default(OrderRequestStatus::PENDING->value);

            $table->date('order_date');

            $table->text('remarks')->nullable();

            $table->date('order_approved_date')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
