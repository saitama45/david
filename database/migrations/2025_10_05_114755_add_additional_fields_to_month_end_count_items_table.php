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
        Schema::table('month_end_count_items', function (Blueprint $table) {
            $table->string('area')->nullable()->after('item_name');
            $table->string('category2')->nullable()->after('area');
            $table->string('category')->nullable()->after('category2');
            $table->string('brand')->nullable()->after('category');
            $table->decimal('current_soh', 15, 4)->default(0)->after('uom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('month_end_count_items', function (Blueprint $table) {
            $table->dropColumn(['area', 'category2', 'category', 'brand', 'current_soh']);
        });
    }
};
