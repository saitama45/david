<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CPO Orders: a store buys from an outside vendor and records the purchase with the
     * vendor's invoice or receipt.
     *
     * cpo_vendors is the vendor list the CPO Orders form picks from and adds to (never
     * deleted). Each order keeps a copy of the vendor's name, TIN and address as they were
     * when it was placed, so a later edit of the vendor does not rewrite past purchases.
     */
    public function up(): void
    {
        Schema::create('cpo_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->nullable()->constrained('entities');
            $table->string('name');
            $table->string('tin', 50)->nullable();
            $table->string('address', 500);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['entity_id', 'name'], 'cpo_vendors_entity_name_unique');
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->foreignId('cpo_vendor_id')->nullable()->constrained('cpo_vendors');
            $table->string('cpo_vendor_name')->nullable();
            $table->string('cpo_vendor_tin', 50)->nullable();
            $table->string('cpo_vendor_address', 500)->nullable();
        });

        // The invoice / receipt files of a CPO order. One upload creating orders for several
        // stores links the same files to each of them.
        Schema::create('cpo_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpo_order_attachments');

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cpo_vendor_id');
            $table->dropColumn(['cpo_vendor_name', 'cpo_vendor_tin', 'cpo_vendor_address']);
        });

        Schema::dropIfExists('cpo_vendors');
    }
};
