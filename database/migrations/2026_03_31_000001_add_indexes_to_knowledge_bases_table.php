<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->index(['is_published', 'created_at'], 'idx_kb_published_date');
            $table->index('category', 'idx_kb_category');
            $table->index('title', 'idx_kb_title');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->dropIndex('idx_kb_published_date');
            $table->dropIndex('idx_kb_category');
            $table->dropIndex('idx_kb_title');
        });
    }
};
