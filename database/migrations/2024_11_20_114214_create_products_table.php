<?php

use App\Models\Setting;
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->nullable()->constrained('settings')->nullOnDelete();
            $table->foreignId('unit_of_measurement_id')->nullable()->constrained('settings')->nullOnDelete();
            $table->string('inventory_code')->unique();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('categ_rep')->nullable();
            $table->string('clasi_rep')->nullable();
            $table->string('conversion');
            $table->string('packaging');
            $table->boolean('is_active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
