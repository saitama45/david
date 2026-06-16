<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('id')->constrained('entities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_branches', function (Blueprint $table) {
            $table->dropForeign(['entity_id']);
            $table->dropColumn('entity_id');
        });
    }
};
