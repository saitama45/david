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
        Schema::create('dts_mass_order_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number')->unique();
            $table->unsignedBigInteger('encoder_id');
            $table->string('variant');
            $table->date('date_from');
            $table->date('date_to');
            $table->integer('total_orders')->default(0);
            $table->decimal('total_quantity', 10, 2)->default(0);
            $table->string('status')->default('approved');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('encoder_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dts_mass_order_batches');
    }
};
