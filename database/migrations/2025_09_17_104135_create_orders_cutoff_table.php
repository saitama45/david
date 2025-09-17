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
                Schema::create('orders_cutoff', function (Blueprint $table) {
            $table->id();
            $table->string('ordering_template');
            $table->integer('cutoff_1_day');
            $table->time('cutoff_1_time');
            $table->string('days_covered_1')->nullable();
            $table->integer('cutoff_2_day')->nullable();
            $table->time('cutoff_2_time')->nullable();
            $table->string('days_covered_2')->nullable();
            $table->timestamps();

                                    $table->index('ordering_template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_cutoff');
    }
};
