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
        Schema::create('pos_masterfiles', function (Blueprint $table) {
            $table->id(); // Auto-incrementing primary key
            $table->string('ItemCode')->unique(); // ItemCode, assuming it should be unique
            $table->string('ItemDescription');
            $table->string('Category')->nullable(); // Category, allowing null as it might not always be present
            $table->string('SubCategory')->nullable(); // SubCategory, allowing null
            $table->decimal('SRP', 10, 4); // SRP as decimal with 10 total digits and 4 decimal places
            $table->boolean('is_active')->default(true); // is_active, defaulting to true

            $table->timestamps(); // Adds created_at and updated_at columns
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_masterfiles');
    }
};

