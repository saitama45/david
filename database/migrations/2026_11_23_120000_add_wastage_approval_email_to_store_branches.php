<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is emailed when a wastage of the store gets its final approval (Email Notifications >
 * Wastage approved). Several addresses, separated by commas or semicolons.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_branches', 'wastage_approval_email')) {
            Schema::table('store_branches', function (Blueprint $table) {
                $table->string('wastage_approval_email', 1000)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Forward-only: the addresses typed in would be lost.
    }
};
