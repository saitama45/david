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
        Schema::create('sap_masterfiles', function (Blueprint $table) {
            $table->id();
            $table->string('ItemNo')->unique();
            $table->string('ItemDescription');
            $table->integer('AltQty');
            $table->integer('BaseQty');
            $table->string('AltUOM');
            $table->string('BaseUOM');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sap_masterfiles');
    }
};
