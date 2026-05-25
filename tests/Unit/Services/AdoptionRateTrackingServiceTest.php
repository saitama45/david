<?php

namespace Tests\Unit\Services;

use App\Http\Services\AdoptionRateTrackingService;
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
}
