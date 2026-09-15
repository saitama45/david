<?php

use App\Http\Services\SuccessRateService;
use App\Models\SuccessRateWeeklyTicket;
use App\Models\User;

it('regroups success rate weeks into the month holding each week thursday', function () {
    $user = User::factory()->create();
    $service = app(SuccessRateService::class);

    // Mon 2026-06-29 has its Thursday on 2 Jul, so it belongs to July.
    $service->saveWeek(['week_start' => '2026-06-29', 'order_incoming' => 3, 'order_closed' => 2, 'adoption_rate_override' => 40], $user);
    $service->saveWeek(['week_start' => '2026-07-06', 'adoption_rate_override' => 60], $user);
    $service->saveWeek(['week_start' => '2026-08-03', 'admin_incoming' => 1, 'admin_closed' => 1], $user);

    $trend = $service->getWeeklyTrend([
        'date_from' => '2026-06-29',
        'date_to' => '2026-08-09',
        'period' => 'month',
    ], $user);

    $rows = collect($trend['rows'])->keyBy('period_label');

    expect($rows->keys()->all())->toBe(['Jul 2026', 'Aug 2026'])
        ->and($trend['filters']['period'])->toBe('month')
        ->and($rows['Jul 2026'])->toMatchArray([
            'weeks' => 5,
            'weeks_with_data' => 2,
            'total_tickets' => 3,
            'total_closed' => 2,
            'close_rate' => 66.67,
            'adoption_rate' => 50.0,
            'period_range' => 'Jun 29-Aug 2',
        ])
        ->and($rows['Aug 2026'])->toMatchArray(['weeks' => 1, 'total_tickets' => 1, 'close_rate' => 100.0])
        // Totals stay weekly, so the headline figures do not move with the view.
        ->and($trend['totals']['weeks'])->toBe(6);
});

it('keeps weekly rows by default', function () {
    $user = User::factory()->create();

    $trend = app(SuccessRateService::class)->getWeeklyTrend([
        'date_from' => '2026-06-29',
        'date_to' => '2026-08-09',
    ], $user);

    expect($trend['rows'])->toHaveCount(6)
        ->and($trend['rows'][0]['period_label'])->toBe('Week 27');
});
