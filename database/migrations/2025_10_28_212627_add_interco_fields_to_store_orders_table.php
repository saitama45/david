<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('interco_number')->nullable()->after('batch_reference');
            $table->unsignedBigInteger('sending_store_branch_id')->nullable()->after('store_branch_id');
            $table->text('interco_reason')->nullable()->after('remarks');
            $table->enum('interco_status', ['open', 'approved', 'disapproved', 'committed', 'in_transit', 'received'])->nullable()->after('order_status');

            // Add foreign key constraint
            $table->foreign('sending_store_branch_id')->references('id')->on('store_branches');

            // Add indexes for performance
            $table->index('interco_status');
            $table->index('sending_store_branch_id');
        });

        // Add a filtered unique index for interco_number to allow multiple nulls
        DB::statement('CREATE UNIQUE INDEX store_orders_interco_number_unique ON store_orders(interco_number) WHERE interco_number IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropForeign(['sending_store_branch_id']);
            $table->dropIndex('store_orders_interco_number_unique');
            $table->dropIndex(['interco_status']);
            $table->dropIndex(['sending_store_branch_id']);
            $table->dropColumn(['interco_number', 'sending_store_branch_id', 'interco_reason', 'interco_status']);
        });
    }
};
