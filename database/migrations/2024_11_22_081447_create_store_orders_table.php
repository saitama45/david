<?php

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\User;
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
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encoder_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            $table->foreignId('commiter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')
                ->nullable()->constrained('users');
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->date('order_date');
            $table->enum('order_status', OrderStatus::values())->default(OrderStatus::PENDING->value);
            $table->enum('order_request_status', OrderRequestStatus::values())->default(OrderStatus::PENDING->value);
            $table->enum('manager_approval_status', OrderRequestStatus::values())->default(OrderStatus::PENDING->value);
            $table->string('variant')->default('regular');
            $table->text('remarks')->nullable();
            $table->dateTime('approval_action_date')->nullable();
            $table->dateTime('commited_action_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
