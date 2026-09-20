<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('store_branch_id');
            $table->date('business_date');
            // Includes entity, store, business date, terminal AND receipt.
            $table->char('receipt_key', 64)->unique();
            $table->unsignedBigInteger('store_transaction_id')->unique();
            $table->string('source', 20);
            $table->unsignedBigInteger('import_log_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('rows_hash', 64);
            $table->char('destination_hash', 64);
            $table->text('movements');
            $table->timestamp('pos_verified_at')->nullable();
            $table->timestamps();
            $table->index(['entity_id', 'store_branch_id', 'business_date'], 'sales_posting_day');
        });
        Schema::create('sales_posting_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('store_transaction_id');
            $table->unsignedBigInteger('user_id');
            $table->text('reason');
            $table->text('original_posting');
            $table->text('original_items');
            $table->text('reversal_ids');
            $table->timestamps();
        });
        Schema::create('pos_sync_exceptions', function (Blueprint $table) {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('store_branch_id');
            $table->text('source_identity');
            $table->text('reason');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('retry_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_exceptions');
        Schema::dropIfExists('sales_posting_corrections');
        Schema::dropIfExists('sales_postings');
    }
};
