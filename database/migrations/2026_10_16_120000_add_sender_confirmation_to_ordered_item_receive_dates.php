<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An interco receipt whose quantity differs from what was sent waits for the sending
     * store (status 'for_confirmation'). It records when it was sent for confirmation and the
     * sending store's answer: 'confirmed' (posted at the received quantity) or 'resent' (back to
     * the receiving store to recount), by whom, when and why.
     */
    public function up(): void
    {
        Schema::table('ordered_item_receive_dates', function (Blueprint $table) {
            $table->dateTime('confirmation_requested_at')->nullable();
            $table->string('sender_decision', 20)->nullable();
            $table->foreignId('sender_decided_by')->nullable()->constrained('users');
            $table->dateTime('sender_decided_at')->nullable();
            $table->string('sender_remarks', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ordered_item_receive_dates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_decided_by');
            $table->dropColumn(['confirmation_requested_at', 'sender_decision', 'sender_decided_at', 'sender_remarks']);
        });
    }
};
