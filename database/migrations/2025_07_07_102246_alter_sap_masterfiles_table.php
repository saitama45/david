<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sap_masterfiles', function (Blueprint $table) {
            // --- Drop existing unique constraints ---
            // Based on your controller's validation rules, unique constraints exist.
            // Laravel's default naming convention for unique indexes is `table_column_unique`.
            // We'll try to drop them, but if they don't exist (e.g., if you already removed them manually),
            // this part might throw an error. You can wrap in a try-catch or check Schema::hasColumn
            // if you're unsure, but for a clean migration, assuming they exist is typical.

            // Drop unique constraint for 'ItemNo' if it exists
            // $table->dropUnique('sap_masterfiles_item_no_unique');

            // Drop unique constraint for 'ItemDescription' if it exists
            // $table->dropUnique('sap_masterfiles_item_description_unique');

            // --- Modify columns to be nullable and update data types ---

            // Make 'ItemNo' nullable
            $table->string('ItemNo')->nullable()->change();

            // Make 'ItemDescription' nullable
            $table->string('ItemDescription')->nullable()->change();

            // Change 'AltQty' to decimal(18, 4) and make it nullable
            $table->decimal('AltQty', 18, 4)->nullable()->change();

            // Change 'BaseQty' to decimal(18, 4) and make it nullable
            $table->decimal('BaseQty', 18, 4)->nullable()->change();

            // Make 'AltUOM' nullable
            $table->string('AltUOM')->nullable()->change();

            // Make 'BaseUOM' nullable (it was required in your controller, now making it nullable)
            $table->string('BaseUOM')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sap_masterfiles', function (Blueprint $table) {
            // --- Revert column changes (make them non-nullable and revert types if necessary) ---
            // Note: Reverting data types like decimal can be tricky if data has been inserted
            // that doesn't fit the original type. For simplicity, we'll revert nullability.
            // If you need to strictly revert data types, you'd define the original types here.

            // Revert 'ItemNo' to non-nullable and re-add unique (if desired for rollback)
            // If you truly want it unique again on rollback, uncomment the unique() line.
            $table->string('ItemNo')->nullable(false)->change();
            // $table->unique('ItemNo'); // Uncomment if you want to re-add unique constraint on rollback

            // Revert 'ItemDescription' to non-nullable and re-add unique (if desired for rollback)
            // If you truly want it unique again on rollback, uncomment the unique() line.
            $table->string('ItemDescription')->nullable(false)->change();
            // $table->unique('ItemDescription'); // Uncomment if you want to re-add unique constraint on rollback

            // Revert 'AltQty' to non-nullable (assuming it was before, or keep nullable if that was its original state)
            // For decimal, you might need to specify the original precision/scale if different from 18,4
            $table->decimal('AltQty', 18, 4)->nullable(false)->change(); // Or original precision/scale

            // Revert 'BaseQty' to non-nullable
            $table->decimal('BaseQty', 18, 4)->nullable(false)->change(); // Or original precision/scale

            // Revert 'AltUOM' to non-nullable
            $table->string('AltUOM')->nullable(false)->change();

            // Revert 'BaseUOM' to non-nullable (as it was 'required' in your controller)
            $table->string('BaseUOM')->nullable(false)->change();

            // --- Re-add unique constraints (if they were dropped in 'up' and you want them back on rollback) ---
            // This part is crucial if you want to be able to roll back this migration and restore the unique constraints.
            // Ensure these names match the ones dropped in the 'up' method.
            // If you uncommented the unique() calls above, you don't need these dropUnique calls.
            // However, if you are strictly dropping and re-adding, this is how it would look.
            // For this specific scenario (removing unique permanently for import), you might not need to re-add them in down().
            // But for a complete rollback, it's good practice.
            // $table->unique('ItemNo', 'sap_masterfiles_item_no_unique');
            // $table->unique('ItemDescription', 'sap_masterfiles_item_description_unique');
        });
    }
};  