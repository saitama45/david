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
        Schema::table('image_attachments', function (Blueprint $table) {
            // Add the column to store the ID of the user who uploaded the image.
            // It's nullable in case some old records don't have this information.
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->after('store_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_attachments', function (Blueprint $table) {
            // This will remove the column if you need to roll back the migration.
            $table->dropForeign(['uploaded_by_user_id']);
            $table->dropColumn('uploaded_by_user_id');
        });
    }
};
