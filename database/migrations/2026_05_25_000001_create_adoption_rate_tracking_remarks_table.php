<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adoption_rate_tracking_remarks', function (Blueprint $table) {
            $table->id();
            $table->string('tab_key');
            $table->string('ordering_template');
            $table->foreignId('store_branch_id')->constrained('store_branches')->cascadeOnDelete();
            $table->date('delivery_date');
            $table->text('remarks')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['tab_key', 'ordering_template', 'store_branch_id', 'delivery_date'],
                'art_remarks_unique_row'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adoption_rate_tracking_remarks');
    }
};
