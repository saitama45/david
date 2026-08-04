<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Ordering Timeliness tab used to key its rows on the raw DTS delivery
 * schedule variant, so stores whose F&V schedule is filed under the umbrella
 * "DROPS" variant produced rows (and therefore remarks) under an ordering
 * template of "DROPS". Those rows are now reported as FRUITS AND VEGETABLES,
 * so re-point the saved remarks or they would silently detach from their rows.
 *
 * Only the ordering_timeliness tab is affected: the commit and delivery tabs
 * already derived their template from the order, which mapped DROPS-supplier
 * orders to FRUITS AND VEGETABLES.
 */
return new class extends Migration
{
    private const TAB_KEY = 'ordering_timeliness';
    private const FROM_TEMPLATE = 'DROPS';
    private const TO_TEMPLATE = 'FRUITS AND VEGETABLES';

    public function up(): void
    {
        // (tab_key, ordering_template, store_branch_id, delivery_date) is
        // unique, so drop any DROPS remark whose F&V twin already exists
        // before renaming the rest.
        $this->deleteColliding(self::FROM_TEMPLATE, self::TO_TEMPLATE);

        DB::table('adoption_rate_tracking_remarks')
            ->where('tab_key', self::TAB_KEY)
            ->where('ordering_template', self::FROM_TEMPLATE)
            ->update(['ordering_template' => self::TO_TEMPLATE]);
    }

    public function down(): void
    {
        // Irreversible in the strict sense: remarks originally saved under
        // FRUITS AND VEGETABLES are indistinguishable from the re-pointed ones
        // after up(), so leave the data as-is rather than corrupt it.
    }

    private function deleteColliding(string $from, string $to): void
    {
        DB::table('adoption_rate_tracking_remarks')
            ->where('tab_key', self::TAB_KEY)
            ->where('ordering_template', $from)
            ->whereExists(function ($query) use ($to) {
                $query->select(DB::raw(1))
                    ->from('adoption_rate_tracking_remarks as existing')
                    ->whereColumn('existing.store_branch_id', 'adoption_rate_tracking_remarks.store_branch_id')
                    ->whereColumn('existing.delivery_date', 'adoption_rate_tracking_remarks.delivery_date')
                    ->where('existing.tab_key', self::TAB_KEY)
                    ->where('existing.ordering_template', $to);
            })
            ->delete();
    }
};
