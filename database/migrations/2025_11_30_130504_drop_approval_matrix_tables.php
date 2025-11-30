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
        // Drop tables in reverse order of creation to avoid foreign key constraints.
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('entity_approval_workflows');
        Schema::dropIfExists('approval_matrix_delegations');
        Schema::dropIfExists('approval_matrix_approvers');
        Schema::dropIfExists('approval_matrix_rules');
        Schema::dropIfExists('approval_matrices');

        // Drop columns from other tables that were added by the approval matrix migrations.
        if (Schema::hasTable('store_orders') && Schema::hasColumn('store_orders', 'approval_matrix_id')) {
            // Check for foreign key before dropping it.
            // Note: Laravel's default foreign key naming convention is table_column_foreign.
            // If a custom name was used, this might need adjustment.
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $foreignKeys = array_map(function($fk) {
                return $fk->getName();
            }, $sm->listTableForeignKeys('store_orders'));

            if (in_array('store_orders_approval_matrix_id_foreign', $foreignKeys)) {
                Schema::table('store_orders', function (Blueprint $table) {
                    $table->dropForeign(['approval_matrix_id']);
                });
            }
            
            Schema::table('store_orders', function (Blueprint $table) {
                $table->dropColumn(['approval_matrix_id', 'current_approval_level', 'total_approval_required', 'approval_workflow']);
            });
        }

        if (Schema::hasTable('wastages') && Schema::hasColumn('wastages', 'approval_matrix_id')) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $foreignKeys = array_map(function($fk) {
                return $fk->getName();
            }, $sm->listTableForeignKeys('wastages'));

            if (in_array('wastages_approval_matrix_id_foreign', $foreignKeys)) {
                Schema::table('wastages', function (Blueprint $table) {
                    $table->dropForeign(['approval_matrix_id']);
                });
            }

            Schema::table('wastages', function (Blueprint $table) {
                $table->dropColumn(['approval_matrix_id', 'current_approval_level', 'total_approval_required', 'approval_workflow']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is for dropping tables and is not intended to be reversible.
        // If you need to recreate the tables, you would run the original creation migrations.
    }
};