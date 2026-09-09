<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weekly helpdesk ticket tallies behind the Dashboard "Success Rate" tab.
     *
     * Mirrors the "David - Adoption Rate and Success Rate" workbook: the user
     * keys in Incoming / Closed per module per ISO week and nothing else. The
     * transaction volume behind each module is read live from the Adoption Rate
     * datasets, and every rate (module vs technical split, totals, success rate,
     * close rate) is derived in SuccessRateService — never stored.
     */
    public function up(): void
    {
        Schema::create('success_rate_weekly_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->unsignedSmallInteger('iso_year');
            $table->unsignedTinyInteger('week_no');

            // Ticket counts only. Transaction volume is NOT stored: it is derived
            // per week from the Adoption Rate datasets so it can never go stale
            // against the actual order/commit/receiving/sales/wastage activity.
            foreach (['order', 'commit', 'receiving', 'wastage', 'mec', 'sales_upload'] as $module) {
                $table->unsignedInteger($module.'_incoming')->default(0);
                $table->unsignedInteger($module.'_closed')->default(0);
            }

            // Admin / technical concerns (internet, software, login, equipment).
            // Tracked separately from module concerns and carries no transaction base.
            $table->unsignedInteger('admin_incoming')->default(0);
            $table->unsignedInteger('admin_closed')->default(0);

            // Optional "David defined rate" override; when null the week falls back
            // to the system-computed adoption rate from the Adoption Rate report.
            $table->decimal('adoption_rate_override', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entity_id', 'week_start'], 'srwt_entity_week_unique');
            $table->index(['week_start', 'week_end'], 'srwt_week_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('success_rate_weekly_tickets');
    }
};
