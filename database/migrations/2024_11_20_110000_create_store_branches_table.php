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
        Schema::create('store_branches', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code');
            $table->string('name');
            $table->string('brand_name')->nullable();
            $table->string('brand_code')->nullable();
            $table->string('location_code')->nullable();
            $table->string('store_status');
            $table->string('tin')->nullable();
            $table->string('complete_address')->nullable();
            $table->string('head_chef')->nullable();
            $table->string('director_operations')->nullable();
            $table->string('vp_operations')->nullable();
            $table->string('store_representative')->nullable();
            $table->string('aom')->nullable();
            $table->string('point_of_contact')->nullable();
            $table->string('contact_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_branches');
    }
};
