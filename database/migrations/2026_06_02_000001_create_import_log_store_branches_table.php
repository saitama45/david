<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_log_store_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_log_id')->constrained('import_logs')->cascadeOnDelete();
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['import_log_id', 'store_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_log_store_branches');
    }
};
