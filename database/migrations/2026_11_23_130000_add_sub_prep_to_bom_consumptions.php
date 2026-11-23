<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Sub-Prep a frozen ingredient line came through. A drink whose BOM uses a prep mix
 * (SP-0001 Matcha Prep Mix) posts the raw materials of the mix, and each such line keeps the
 * mix's code and name as they were, so the reports can say which part came from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_consumptions', function (Blueprint $table) {
            if (! Schema::hasColumn('bom_consumptions', 'sub_prep_code')) {
                $table->string('sub_prep_code')->nullable();
            }

            if (! Schema::hasColumn('bom_consumptions', 'sub_prep_description')) {
                $table->string('sub_prep_description')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only: these lines are the record of what past sales used.
    }
};
