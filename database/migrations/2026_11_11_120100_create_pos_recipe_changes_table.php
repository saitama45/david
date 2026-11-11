<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The change history of the BOM List and the POS Masterfile: one row per line or item
     * created, changed or deleted, from the forms and from the Excel imports alike, with who,
     * when, the old and new values and where the change came from.
     */
    public function up(): void
    {
        if (Schema::hasTable('pos_recipe_changes')) {
            return;
        }

        Schema::create('pos_recipe_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('subject', 20); // bom_line | pos_item
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('pos_code')->nullable();
            $table->string('item_code')->nullable();
            $table->string('bom_uom')->nullable();
            $table->string('assembly')->nullable();
            $table->string('action', 20); // created | updated | deleted
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('source', 30); // form | import | allowed_repeat | audit_log
            $table->string('source_file')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->dateTime('changed_at');
            // The audits row a change was copied from (history from before this table); not
            // unique, since SQL Server allows one NULL in a unique index. The copy skips repeats.
            $table->unsignedBigInteger('audit_id')->nullable()->index();
            $table->timestamps();

            $table->index(['entity_id', 'changed_at']);
            $table->index('pos_code');
            $table->index('item_code');
        });
    }

    public function down(): void
    {
        // Forward-only: this is the audit trail of recipe changes.
    }
};
