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
        Schema::create('sales_budgets', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['Sales', 'Budget']);
            $table->integer('year');
            $table->string('store_code');
            $table->string('store_name')->nullable();
            $table->double('jan')->nullable()->default(0);
            $table->double('feb')->nullable()->default(0);
            $table->double('mar')->nullable()->default(0);
            $table->double('apr')->nullable()->default(0);
            $table->double('may')->nullable()->default(0);
            $table->double('jun')->nullable()->default(0);
            $table->double('jul')->nullable()->default(0);
            $table->double('aug')->nullable()->default(0);
            $table->double('sep')->nullable()->default(0);
            $table->double('oct')->nullable()->default(0);
            $table->double('nov')->nullable()->default(0);
            $table->double('dec')->nullable()->default(0);
            $table->timestamps();

            $table->unique(['type', 'year', 'store_code'], 'sales_budget_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_budgets');
    }
};
