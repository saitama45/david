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
        Schema::create('pos_masterfiles_bom', function (Blueprint $table) {
            $table->id(); // Primary key, auto-incrementing
            $table->string('POSCode');
            $table->string('POSDescription')->nullable();
            $table->string('Assembly')->nullable();
            $table->string('ItemCode');
            $table->string('ItemDescription')->nullable();
            $table->decimal('RecPercent', 8, 4)->nullable(); // Recipe Percentage, e.g., 9999.9999
            $table->decimal('RecipeQty', 12, 4)->nullable();  // Recipe Quantity, e.g., 99999999.9999
            $table->string('RecipeUOM', 50)->nullable();
            $table->decimal('BOMQty', 12, 4)->nullable();     // Bill of Materials Quantity
            $table->string('BOMUOM', 50)->nullable();
            $table->decimal('UnitCost', 15, 4)->nullable();   // Unit Cost, e.g., 99999999999.9999
            $table->decimal('TotalCost', 15, 4)->nullable();  // Total Cost

            // Foreign keys for created_by and updated_by
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps(); // created_at and updated_at

            // Define foreign key constraints
            // CRITICAL FIX: Changed onDelete('set null') to onDelete('no action') to prevent cycles/multiple cascade paths error on SQL Server
            $table->foreign('created_by')->references('id')->on('users')->onDelete('no action');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('no action');

            // Add indexes for frequently queried columns
            $table->index('POSCode');
            $table->index('ItemCode');
            $table->index('Assembly');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_masterfiles_bom');
    }
};

