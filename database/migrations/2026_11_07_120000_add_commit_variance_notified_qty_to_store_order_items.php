<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The committed quantity the store was last emailed about for this line (Email Notifications,
     * commit variance), so the same variance is not emailed twice and a changed one is. Null: never.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('store_order_items', 'commit_variance_notified_qty')) {
            Schema::table('store_order_items', function (Blueprint $table) {
                $table->float('commit_variance_notified_qty')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Forward-only: the column holds which variances were already emailed.
    }
};
