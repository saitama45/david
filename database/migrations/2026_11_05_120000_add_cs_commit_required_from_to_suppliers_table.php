<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordering Template Approval's CS Commit Required switch: set while it is on, to the moment it
     * was turned on. An order placed from then on stays out of Inbound Orders until CS commits it.
     * Null for every template, so nothing changes until someone turns it on.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'cs_commit_required_from')) {
                $table->dateTime('cs_commit_required_from')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only: dropping the column would release every order waiting for its commit.
    }
};
