<?php

namespace Tests\Unit\Services;

use App\Http\Services\AdoptionRateTrackingService;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\Wastage;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class AdoptionRateTrackingServiceTest extends TestCase
{
    public function test_sales_upload_network_days_counts_weekday_upload_windows(): void
    {
        $this->assertSame(0, $this->salesUploadNetworkDays('2026-02-05', '2026-02-05'));
        $this->assertSame(1, $this->salesUploadNetworkDays('2026-02-06', '2026-02-09'));
        $this->assertSame(2, $this->salesUploadNetworkDays('2026-01-05', '2026-01-07'));
    }

    public function test_sales_upload_network_days_uses_next_monday_as_weekend_base(): void
    {
        $this->assertSame(0, $this->salesUploadNetworkDays('2026-01-10', '2026-01-12'));
        $this->assertSame(0, $this->salesUploadNetworkDays('2026-01-11', '2026-01-12'));
        $this->assertSame(1, $this->salesUploadNetworkDays('2026-01-24', '2026-01-27'));
    }

    public function test_wastage_final_approval_uses_level_two_date_or_status(): void
    {
        $this->assertTrue($this->isWastageFinalApproved(new Wastage([
            'approved_level2_date' => '2026-02-01 12:00:00',
            'wastage_status' => 'approved_lvl1',
        ])));

        $this->assertTrue($this->isWastageFinalApproved(new Wastage([
            'wastage_status' => 'approved_lvl2',
        ])));

        $this->assertFalse($this->isWastageFinalApproved(new Wastage([
            'wastage_status' => 'approved_lvl1',
        ])));
    }

    public function test_overall_week_buckets_are_clipped_to_selected_range(): void
    {
        $weeks = $this->buildWeekBuckets('2026-03-03', '2026-03-14');

        $this->assertSame('Mar3-Mar8', $weeks[0]['label']);
        $this->assertSame('2026-03-03', $weeks[0]['start_date']);
        $this->assertSame('2026-03-08', $weeks[0]['end_date']);
        $this->assertSame('Mar9-Mar14', $weeks[1]['label']);
        $this->assertSame('2026-03-14', $weeks[1]['end_date']);
    }

    public function test_overall_status_rate_excludes_non_denominator_values(): void
    {
        $rate = $this->statusRate(collect([
            ['plotted' => 'Yes'],
            ['plotted' => 'No'],
            ['plotted' => 'No order'],
        ]), 'plotted', ['Yes'], ['Yes', 'No']);

        $this->assertSame(50.0, $rate);
    }

    public function test_overall_status_rate_returns_null_without_denominator(): void
    {
        $rate = $this->statusRate(collect([
            ['plotted' => 'No order'],
            ['plotted' => 'No order'],
        ]), 'plotted', ['Yes'], ['Yes', 'No']);

        $this->assertNull($rate);
    }

    public function test_overall_selected_range_rate_is_weighted_by_rows(): void
    {
        $rate = $this->statusRate(collect([
            ['on_time' => 'Yes'],
            ['on_time' => 'No'],
            ['on_time' => 'No'],
            ['on_time' => 'Yes'],
        ]), 'on_time', ['Yes'], ['Yes', 'No']);

        $this->assertSame(50.0, $rate);
    }

    public function test_overall_commit_rate_combines_fg_and_traded_excluding_na(): void
    {
        $rate = $this->commitOverallRate(collect([
            ['fg_on_time' => 'NA', 'traded_on_time' => 'Yes'],
            ['fg_on_time' => 'No', 'traded_on_time' => 'Yes'],
        ]));

        $this->assertSame(66.67, $rate);
    }

    public function test_overall_simple_average_excludes_na_values(): void
    {
        $this->assertSame(75.0, $this->simpleAverage([100, null, 50]));
        $this->assertNull($this->simpleAverage([null, null]));
    }

    public function test_delivery_logging_status_marks_cpo_as_na(): void
    {
        $deliveryDate = Carbon::parse('2026-07-01')->startOfDay();

        $this->assertSame('NA', $this->deliveryLoggingStatus('CPO', $deliveryDate->copy(), $deliveryDate));
        $this->assertSame('NA', $this->deliveryLoggingStatus(' cpo ', null, $deliveryDate));
    }

    public function test_delivery_logging_status_keeps_non_cpo_timeliness_rules(): void
    {
        $deliveryDate = Carbon::parse('2026-07-01')->startOfDay();

        $this->assertSame('Yes', $this->deliveryLoggingStatus('GSI-B', $deliveryDate->copy(), $deliveryDate));
        $this->assertSame('No', $this->deliveryLoggingStatus('GSI-B', $deliveryDate->copy()->addDay(), $deliveryDate));
        $this->assertSame('No', $this->deliveryLoggingStatus('GSI-B', null, $deliveryDate));
    }

    public function test_delivery_logging_rate_averages_eligible_stores_equally_and_excludes_cpo(): void
    {
        $rate = $this->deliveryLoggingAdoptionRate(collect([
            ['store_branch_id' => 1, 'on_time' => 'Yes'],
            ['store_branch_id' => 1, 'on_time' => 'No'],
            ['store_branch_id' => 1, 'on_time' => 'No'],
            ['store_branch_id' => 1, 'on_time' => 'No'],
            ['store_branch_id' => 2, 'on_time' => 'Yes'],
            ['store_branch_id' => 2, 'on_time' => 'NA'],
            ['store_branch_id' => 3, 'on_time' => 'NA'],
        ]));

        $this->assertSame(62.5, $rate);
    }

    public function test_delivery_logging_rate_is_null_when_all_rows_are_cpo(): void
    {
        $rate = $this->deliveryLoggingAdoptionRate(collect([
            ['store_branch_id' => 1, 'on_time' => 'NA'],
            ['store_branch_id' => 2, 'on_time' => 'NA'],
        ]));

        $this->assertNull($rate);
    }

    public function test_commit_exclusion_includes_cpo_and_every_drops_supplier_variant(): void
    {
        $this->assertTrue($this->isAutomatedCommitOrder('GSI-B', 'CPO'));
        $this->assertTrue($this->isAutomatedCommitOrder('DROPS', 'FRUITS AND VEGETABLES'));
        $this->assertTrue($this->isAutomatedCommitOrder('DROPS', 'ICE CREAM'));
        $this->assertTrue($this->isAutomatedCommitOrder('DROPS', 'SALMON'));
        $this->assertTrue($this->isAutomatedCommitOrder('DROPS', 'DROPS'));
        $this->assertFalse($this->isAutomatedCommitOrder('GSI-B', 'GSI-B'));
    }

    public function test_commit_status_returns_na_when_excluded_and_preserves_manual_rules(): void
    {
        $deliveryDate = Carbon::parse('2026-07-02')->startOfDay();

        $this->assertSame('NA', $this->commitStatus(null, $deliveryDate, true));
        $this->assertSame('Yes', $this->commitStatus($deliveryDate->copy()->subDay(), $deliveryDate));
        $this->assertSame('No', $this->commitStatus($deliveryDate->copy(), $deliveryDate));
        $this->assertSame('No', $this->commitStatus(null, $deliveryDate));
    }

    public function test_commit_rates_average_eligible_stores_equally_and_exclude_automated_only_stores(): void
    {
        $rows = collect([
            ['store_branch_id' => 1, 'fg_on_time' => 'Yes', 'traded_on_time' => 'No'],
            ['store_branch_id' => 1, 'fg_on_time' => 'No', 'traded_on_time' => 'No'],
            ['store_branch_id' => 2, 'fg_on_time' => 'Yes', 'traded_on_time' => 'Yes'],
            ['store_branch_id' => 3, 'fg_on_time' => 'NA', 'traded_on_time' => 'NA'],
        ]);

        $this->assertSame(75.0, $this->statusAdoptionRateByStore($rows, 'fg_on_time'));
        $this->assertSame(50.0, $this->statusAdoptionRateByStore($rows, 'traded_on_time'));
        $this->assertSame(62.5, $this->commitAdoptionRateByStore($rows));
    }

    public function test_commit_rate_is_null_when_all_stores_only_have_automated_orders(): void
    {
        $rows = collect([
            ['store_branch_id' => 1, 'fg_on_time' => 'NA', 'traded_on_time' => 'NA'],
            ['store_branch_id' => 2, 'fg_on_time' => 'NA', 'traded_on_time' => 'NA'],
        ]);

        $this->assertNull($this->statusAdoptionRateByStore($rows, 'fg_on_time'));
        $this->assertNull($this->statusAdoptionRateByStore($rows, 'traded_on_time'));
        $this->assertNull($this->commitAdoptionRateByStore($rows));
    }

    public function test_drops_umbrella_template_is_reported_as_fruits_and_vegetables(): void
    {
        $this->assertSame('FRUITS AND VEGETABLES', $this->normalizeTemplate('DROPS'));
        $this->assertSame('FRUITS AND VEGETABLES', $this->normalizeTemplate(' drops '));
        $this->assertSame('FRUITS AND VEGETABLES', $this->normalizeTemplate('FRUITS AND VEGETABLES'));
        $this->assertSame('ICE CREAM', $this->normalizeTemplate('ICE CREAM'));
        $this->assertSame('GSI-P', $this->normalizeTemplate('GSI-P'));
    }

    public function test_fruits_and_vegetables_filter_also_pulls_the_drops_schedule_variant(): void
    {
        $this->assertSame(
            ['FRUITS AND VEGETABLES', 'DROPS'],
            $this->scheduleVariantsForTemplates(['FRUITS AND VEGETABLES'])
        );
        $this->assertSame(
            ['FRUITS AND VEGETABLES', 'DROPS'],
            $this->scheduleVariantsForTemplates(['DROPS'])
        );
        $this->assertSame(['ICE CREAM'], $this->scheduleVariantsForTemplates(['ICE CREAM']));
        $this->assertSame([], $this->scheduleVariantsForTemplates([]));
    }

    public function test_order_required_templates_flag_a_missing_order_as_no(): void
    {
        $this->assertTrue($this->requiresOrderEveryDeliveryDate('GSI-P'));
        $this->assertTrue($this->requiresOrderEveryDeliveryDate('GSI-B'));
        $this->assertTrue($this->requiresOrderEveryDeliveryDate('PUL-O'));
        $this->assertTrue($this->requiresOrderEveryDeliveryDate('FRUITS AND VEGETABLES'));
        $this->assertTrue($this->requiresOrderEveryDeliveryDate('DROPS'));
    }

    public function test_optional_delivery_templates_keep_the_no_order_default(): void
    {
        $this->assertFalse($this->requiresOrderEveryDeliveryDate('ICE CREAM'));
        $this->assertFalse($this->requiresOrderEveryDeliveryDate('CPO'));
        $this->assertFalse($this->requiresOrderEveryDeliveryDate('SALMON'));
    }

    public function test_dashboard_meta_reuses_the_report_whole_period_rate_and_resolved_stores(): void
    {
        $weekKey = '2026-05-01|2026-05-03';
        $overall = [
            'rows' => collect([[
                'store' => 'Store 1',
                'store_code' => 'S1',
                'weeks' => [[
                    'key' => $weekKey,
                    'label' => 'May1-May3',
                    'start_date' => '2026-05-01',
                    'end_date' => '2026-05-03',
                ]],
                'indicators' => [],
                'weekly_averages' => [$weekKey => 2.44],
            ]]),
            'totals' => [
                'stores' => 1,
                'weeks' => 1,
                'overall_rate' => 9.11,
                'indicator_rates' => [
                    ['no' => 3, 'indicator' => 'Timeliness of Receiving of Orders', 'rate' => 25.21],
                ],
            ],
            'filters' => [
                'date_from' => '2026-05-01',
                'date_to' => '2026-07-14',
                'store_ids' => [10, 20],
            ],
        ];
        $service = $this->getMockBuilder(AdoptionRateTrackingService::class)
            ->onlyMethods(['getOverallAdoptionRateData'])
            ->getMock();
        $service->expects($this->once())
            ->method('getOverallAdoptionRateData')
            ->willReturn($overall);

        $trend = $service->getWeeklyAdoptionTrend([], new \App\Models\User());

        $this->assertSame(9.11, $trend['meta']['overall_rate']);
        $this->assertSame([10, 20], $trend['meta']['store_ids']);
        $this->assertSame('2026-05-01', $trend['meta']['date_from']);
        $this->assertSame('2026-07-14', $trend['meta']['date_to']);
        $this->assertSame(25.21, $trend['meta']['indicator_rates'][0]['rate']);
        $this->assertSame([25.21, 25.21], $trend['combined']->firstWhere('label', 'Timeliness of Receiving of Orders')['data']);
        $this->assertSame([9.11, 9.11], $trend['combined']->firstWhere('label', 'Overall')['data']);
        $this->assertSame([2.44], $trend['per_store']->first()['data']);
    }

    private function salesUploadNetworkDays(string $salesDate, string $uploadDate): int
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'salesUploadNetworkDays');
        $method->setAccessible(true);

        return $method->invoke(
            new AdoptionRateTrackingService(),
            Carbon::parse($salesDate)->startOfDay(),
            Carbon::parse($uploadDate)->startOfDay()
        );
    }

    private function isWastageFinalApproved(Wastage $wastage): bool
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'isWastageFinalApproved');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $wastage);
    }

    private function buildWeekBuckets(string $dateFrom, string $dateTo): array
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'buildWeekBuckets');
        $method->setAccessible(true);

        return $method->invoke(
            new AdoptionRateTrackingService(),
            Carbon::parse($dateFrom)->startOfDay(),
            Carbon::parse($dateTo)->startOfDay()
        );
    }

    private function statusRate($rows, string $field, array $yesValues, array $denominatorValues): ?float
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'statusRate');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $rows, $field, $yesValues, $denominatorValues);
    }

    private function commitOverallRate($rows): ?float
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'commitOverallRate');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $rows);
    }

    private function simpleAverage(array $rates): ?float
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'simpleAverage');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $rates);
    }

    private function deliveryLoggingStatus(string $template, ?Carbon $loggingDate, Carbon $deliveryDate): string
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'deliveryLoggingStatus');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $template, $loggingDate, $deliveryDate);
    }

    private function deliveryLoggingAdoptionRate($rows): ?float
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'deliveryLoggingAdoptionRate');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $rows);
    }

    private function isAutomatedCommitOrder(string $supplierCode, string $template): bool
    {
        $order = new StoreOrder();
        $order->setRelation('supplier', new Supplier(['supplier_code' => $supplierCode]));
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'isAutomatedCommitOrder');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $order, $template);
    }

    private function commitStatus(?Carbon $commitDate, Carbon $deliveryDate, bool $excluded = false): string
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'commitStatus');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $commitDate, $deliveryDate, $excluded);
    }

    private function statusAdoptionRateByStore($rows, string $field): ?float
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'statusAdoptionRateByStore');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $rows, $field);
    }

    private function normalizeTemplate(?string $template): string
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'normalizeTemplate');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $template);
    }

    private function scheduleVariantsForTemplates(array $templates): array
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'scheduleVariantsForTemplates');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $templates);
    }

    private function requiresOrderEveryDeliveryDate(string $template): bool
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'requiresOrderEveryDeliveryDate');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $template);
    }

    private function commitAdoptionRateByStore($rows): ?float
    {
        $method = new ReflectionMethod(AdoptionRateTrackingService::class, 'commitAdoptionRateByStore');
        $method->setAccessible(true);

        return $method->invoke(new AdoptionRateTrackingService(), $rows);
    }
}
