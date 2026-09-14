<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only trail of every transition of a business-rule exception
     * request. Written by RuleExceptionService in the same transaction as the
     * transition itself, so it does not depend on Eloquent model events.
     */
    public function up(): void
    {
        Schema::create('rule_exception_request_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('rule_exception_request_id')->constrained('rule_exception_requests');

            $table->string('action', 16); // submitted | approved | rejected | cancelled | consumed | expired
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);

            // Null for system actions (scheduled expiry).
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('remarks')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->dateTime('created_at');

            $table->index(['rule_exception_request_id', 'created_at'], 'rera_request_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_exception_request_actions');
    }
};
