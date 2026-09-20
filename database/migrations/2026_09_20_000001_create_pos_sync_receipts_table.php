<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('store_branch_id');
            $table->char('source_key', 64)->unique();
            $table->text('source_identity');
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('store_transaction_id');
            $table->unsignedBigInteger('import_log_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_receipts');
    }
};
