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
            $table->string('brand_name');
            $table->string('brand_code');
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('tin')->nullable();
            $table->string('point_of_contact')->nullable();
            $table->string('store_representative_email')->nullable();
            $table->string('head_chef_email')->nullable();
            $table->string('dir_ops_email')->nullable();
            $table->string('vp_ops_email')->nullable();
            $table->string('address')->nullable();
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
