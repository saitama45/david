<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('id')->constrained('entities')->nullOnDelete();
        });

        $nonosId = DB::table('entities')->where('code', 'NONOS')->value('id');
        if ($nonosId) {
            DB::table('import_logs')->whereNull('entity_id')->update(['entity_id' => $nonosId]);
        }
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropForeign(['entity_id']);
            $table->dropColumn('entity_id');
        });
    }
};
