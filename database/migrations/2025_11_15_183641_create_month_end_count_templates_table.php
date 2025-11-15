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
        Schema::create('month_end_count_templates', function (Blueprint $table) {
            $table->id();
            $table->string('item_code');
            $table->string('item_name');
            $table->string('area')->nullable();
            $table->string('category_2')->nullable();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('packaging_config')->nullable();
            $table->string('config')->nullable();
            $table->string('uom')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');

            // Add indexes for better performance
            $table->index('item_code');
            $table->index('item_name');
            $table->index('category');
            $table->index('brand');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('month_end_count_templates');
    }
};
