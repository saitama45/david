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
        Schema::create('cash_pull_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_branch_id')->constrained();
            $table->string('vendor');
            $table->date('date_needed');
            $table->string('vendor_address');
            $table->string('status')->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_pull_outs');
    }
};
