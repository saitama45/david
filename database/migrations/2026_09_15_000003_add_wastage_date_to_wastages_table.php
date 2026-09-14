<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The date the wastage actually happened, as entered by the store.
     *
     * Wastage timeliness ("recorded within 1 working day") used to compare
     * created_at with itself, so it could never be late. Nullable: existing
     * records fall back to their created_at date.
     */
    public function up(): void
    {
        Schema::table('wastages', function (Blueprint $table) {
            $table->date('wastage_date')->nullable()->after('wastage_no');
        });
    }

    public function down(): void
    {
        Schema::table('wastages', function (Blueprint $table) {
            $table->dropColumn('wastage_date');
        });
    }
};
