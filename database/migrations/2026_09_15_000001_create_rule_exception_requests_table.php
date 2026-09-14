<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-rule exception requests.
     *
     * A store user who cannot meet a business rule or deadline asks for an
     * exception; the module's approver decides. An approved "unlock" request is
     * a one-time grant consumed by the transaction it was raised for; an approved
     * "excuse" request marks a late item as Excused in the Adoption Rate report.
     *
     * No cascading deletes: these rows are an audit record of a rule being
     * overridden and must outlive anything they reference.
     */
    public function up(): void
    {
        Schema::create('rule_exception_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('store_branch_id')->constrained('store_branches');

            // Registry key in config/rule_exceptions.php, e.g. mass_order.late_order.
            $table->string('rule_key', 64);
            $table->string('module', 32);
            $table->string('type', 16); // unlock | excuse

            // Canonical identity of the one thing this exception applies to.
            $table->string('subject_key', 191);
            $table->json('context')->nullable();

            $table->string('reason_code', 32);
            $table->text('justification');
            $table->string('attachment_path')->nullable();

            $table->string('status', 16)->default('pending');

            $table->foreignId('requested_by')->constrained('users');
            $table->dateTime('requested_at');

            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_remarks')->nullable();

            // Unlock grants only: usable until this moment, then expired.
            $table->dateTime('valid_until')->nullable();

            $table->foreignId('consumed_by')->nullable()->constrained('users');
            $table->dateTime('consumed_at')->nullable();
            $table->string('consumed_ref_type', 64)->nullable();
            $table->string('consumed_ref_id', 64)->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'status', 'module'], 'rer_entity_status_module_index');
            $table->index(['store_branch_id', 'rule_key'], 'rer_store_rule_index');
        });

        // One open (pending or approved) request per rule + subject. SQL Server
        // filtered index, so decided/consumed history never blocks a new request.
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement(
                "CREATE UNIQUE INDEX rer_open_subject_unique ON rule_exception_requests (entity_id, rule_key, subject_key) WHERE status IN ('pending', 'approved')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_exception_requests');
    }
};
