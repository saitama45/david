<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('error_message');
            $table->timestamp('last_heartbeat_at')->nullable()->after('processing_started_at');
            $table->timestamp('failed_at')->nullable()->after('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn([
                'processing_started_at',
                'last_heartbeat_at',
                'failed_at',
            ]);
        });
    }
};
