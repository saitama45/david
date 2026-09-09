<?php

use App\Http\Services\SuccessRateService;
use App\Models\SuccessRateWeeklyTicket;

/**
 * Locks the derived rates to the "David - Adoption Rate and Success Rate"
 * workbook. Expected values are the numbers the spreadsheet itself stores for
 * those weeks, so a formula regression here is a visible mismatch with the file.
 *
 * Ticket counts are hand-keyed; transaction volume is passed in the way the
 * service receives it at runtime — derived per module from the Adoption Rate
 * datasets, never stored on the record.
 *
 * Reflection is used deliberately: buildRow()/buildTotals() are pure functions
 * of their inputs, so exercising them directly keeps this test free of the
 * database and of the (expensive) Adoption Rate trend.
 */
function invokePrivate(SuccessRateService $service, string $method, array $args)
{
    $reflection = new ReflectionMethod(SuccessRateService::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($service, $args);
}

function successRateService(): SuccessRateService
{
    // buildRow()/buildTotals() never touch the collaborator, so a stub keeps the
    // test from booting the whole Adoption Rate stack.
    $adoption = Mockery::mock(App\Http\Services\AdoptionRateTrackingService::class);

    return new SuccessRateService($adoption);
}

function ticketRecord(array $values): SuccessRateWeeklyTicket
{
    $record = new SuccessRateWeeklyTicket;

    foreach (SuccessRateWeeklyTicket::countColumns() as $column) {
        $record->{$column} = $values[$column] ?? 0;
    }

    $record->adoption_rate_override = $values['adoption_rate_override'] ?? null;

    return $record;
}

function week(string $start, string $end, int $no): array
{
    return [
        'start_date' => $start,
        'end_date' => $end,
        'week_no' => $no,
        'iso_year' => 2026,
        'label' => 'wk',
    ];
}

it('reproduces the workbook figures for Week 19', function () {
    // Sheet row 23 tickets: Order 0/0, Commit 0/0, Receiving 0/0, Wastage 2/0,
    // MEC 2/2, Sales Upload 0/0, Admin 2/0.
    // Sheet row 23 transactions: Order 49, Commit 22, Receiving 43, Wastage 135,
    // MEC (empty), Sales Upload 49 -> AB23 = 298.
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-05-04', '2026-05-10', 19),
        ticketRecord([
            'wastage_incoming' => 2,
            'mec_incoming' => 2, 'mec_closed' => 2,
            'admin_incoming' => 2,
        ]),
        null,
        ['order' => 49, 'commit' => 22, 'receiving' => 43, 'wastage' => 135, 'sales_upload' => 49],
    ]);

    expect($row['module_concerns'])->toBe(4)          // W23
        ->and($row['technical_concerns'])->toBe(2)    // X23
        ->and($row['total_tickets'])->toBe(6)         // Y23
        ->and($row['total_closed'])->toBe(2)          // Z23
        ->and($row['total_open'])->toBe(4)            // AA23
        ->and($row['total_transactions'])->toBe(298)  // AB23
        ->and($row['success_rate'])->toBe(97.99)      // AC23 = 0.9798657...
        ->and($row['close_rate'])->toBe(33.33);       // AD23 = 0.3333333...
});

it('reproduces the workbook figures for Week 36', function () {
    // Sheet row 40 tickets: Order 1/1, Receiving 8/0, Wastage 1/0, MEC 2/2.
    // Sheet row 40 transactions: 142 + 75 + 135 + 236 + 145 -> AB40 = 733.
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-08-31', '2026-09-06', 36),
        ticketRecord([
            'order_incoming' => 1, 'order_closed' => 1,
            'receiving_incoming' => 8,
            'wastage_incoming' => 1,
            'mec_incoming' => 2, 'mec_closed' => 2,
        ]),
        null,
        ['order' => 142, 'commit' => 75, 'receiving' => 135, 'wastage' => 236, 'sales_upload' => 145],
    ]);

    expect($row['module_concerns'])->toBe(12)         // W40
        ->and($row['technical_concerns'])->toBe(0)    // X40
        ->and($row['total_tickets'])->toBe(12)        // Y40
        ->and($row['total_closed'])->toBe(3)          // Z40
        ->and($row['total_open'])->toBe(9)            // AA40
        ->and($row['total_transactions'])->toBe(733)  // AB40
        ->and($row['success_rate'])->toBe(98.36)      // AC40 = 0.9836289...
        ->and($row['close_rate'])->toBe(25.0);        // AD40 = 0.25
});

it('exposes the per-module transaction volume that makes up the denominator', function () {
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-05-04', '2026-05-10', 19),
        null,
        null,
        ['order' => 49, 'commit' => 22, 'receiving' => 43, 'wastage' => 135, 'sales_upload' => 49],
    ]);

    expect($row['order_transactions'])->toBe(49)
        ->and($row['commit_transactions'])->toBe(22)
        ->and($row['receiving_transactions'])->toBe(43)
        ->and($row['wastage_transactions'])->toBe(135)
        ->and($row['sales_upload_transactions'])->toBe(49)
        // MEC has no Adoption Rate indicator; the workbook's MEC transactions
        // column is empty for every week, so it must never add to the total.
        ->and($row['mec_transactions'])->toBe(0)
        ->and($row['total_transactions'])->toBe(298);
});

it('ignores transaction counts for modules that have no adoption indicator', function () {
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-05-04', '2026-05-10', 19),
        null,
        null,
        // A stray 'mec' key must not leak into the denominator.
        ['order' => 10, 'mec' => 999],
    ]);

    expect($row['total_transactions'])->toBe(10)
        ->and($row['mec_transactions'])->toBe(0);
});

it('prefers the manual adoption override over the system rate', function () {
    $service = successRateService();
    $bucket = week('2026-05-04', '2026-05-10', 19);

    $overridden = invokePrivate($service, 'buildRow', [
        $bucket, ticketRecord(['adoption_rate_override' => 51.2]), 63.0, [],
    ]);

    $fallback = invokePrivate($service, 'buildRow', [$bucket, ticketRecord([]), 63.0, []]);

    expect($overridden['adoption_rate'])->toBe(51.2)
        ->and($overridden['adoption_rate_override'])->toBe(51.2)
        ->and($fallback['adoption_rate'])->toBe(63.0)
        ->and($fallback['adoption_rate_override'])->toBeNull();
});

it('returns null rates instead of dividing by zero on a week with no activity', function () {
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-05-04', '2026-05-10', 19), null, null, [],
    ]);

    expect($row['has_record'])->toBeFalse()
        ->and($row['total_tickets'])->toBe(0)
        ->and($row['total_transactions'])->toBe(0)
        ->and($row['success_rate'])->toBeNull()
        ->and($row['close_rate'])->toBeNull()
        ->and($row['adoption_rate'])->toBeNull();
});

it('reports no success rate for a week whose tickets were never encoded', function () {
    // Transactions are live, so an un-encoded week has a real denominator and
    // zero tickets. Scoring that 100% would invent a result for a week nobody
    // has tallied and drag the running average up.
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-05-04', '2026-05-10', 19), null, null, ['order' => 200],
    ]);

    expect($row['has_record'])->toBeFalse()
        ->and($row['total_transactions'])->toBe(200)
        ->and($row['total_tickets'])->toBe(0)
        ->and($row['success_rate'])->toBeNull()
        ->and($row['close_rate'])->toBeNull();
});

it('scores a deliberately zero-ticket week as a perfect week', function () {
    // A week the user encoded as all zeros is a real, flawless result.
    $row = invokePrivate(successRateService(), 'buildRow', [
        week('2026-05-04', '2026-05-10', 19), ticketRecord([]), null, ['order' => 200],
    ]);

    expect($row['has_record'])->toBeTrue()
        ->and($row['total_tickets'])->toBe(0)
        ->and($row['success_rate'])->toBe(100.0);
});

it('averages only the weeks that have a rate and splits ticket types', function () {
    $service = successRateService();

    $rows = collect([
        invokePrivate($service, 'buildRow', [
            week('2026-05-04', '2026-05-10', 19),
            ticketRecord(['order_incoming' => 4, 'order_closed' => 2, 'admin_incoming' => 2]),
            50.0,
            ['order' => 298],
        ]),
        // A week with no activity at all contributes no rate to any average.
        invokePrivate($service, 'buildRow', [week('2026-05-11', '2026-05-17', 20), null, null, []]),
    ]);

    $totals = invokePrivate($service, 'buildTotals', [$rows]);

    expect($totals['weeks'])->toBe(2)
        ->and($totals['weeks_with_data'])->toBe(1)
        ->and($totals['module_concerns'])->toBe(4)
        ->and($totals['technical_concerns'])->toBe(2)
        // 6 tickets over 298 transactions -> 1 - 6/298
        ->and($totals['running_success_rate'])->toBe(97.99)
        ->and($totals['running_adoption_rate'])->toBe(50.0)
        ->and($totals['module_share'])->toBe(66.67)
        ->and($totals['technical_share'])->toBe(33.33);
});

it('counts a row as one transaction when any status field carries a verdict', function () {
    $service = successRateService();

    // Commit rows carry two statuses; either one makes it a real transaction,
    // and both together must still count as one.
    expect(invokePrivate($service, 'countsAsTransaction', [
        ['fg_on_time' => 'Yes', 'traded_on_time' => 'N/A'], ['fg_on_time', 'traded_on_time'],
    ]))->toBeTrue()
        ->and(invokePrivate($service, 'countsAsTransaction', [
            ['fg_on_time' => 'N/A', 'traded_on_time' => 'No'], ['fg_on_time', 'traded_on_time'],
        ]))->toBeTrue()
        // Rows the report excludes (CPO / automated) are not transactions.
        ->and(invokePrivate($service, 'countsAsTransaction', [
            ['fg_on_time' => 'N/A', 'traded_on_time' => 'N/A'], ['fg_on_time', 'traded_on_time'],
        ]))->toBeFalse()
        ->and(invokePrivate($service, 'countsAsTransaction', [[], ['plotted']]))->toBeFalse();
});

it('buckets a transaction date onto its Monday', function () {
    $service = successRateService();

    expect(invokePrivate($service, 'weekStartFor', ['2026-05-08']))->toBe('2026-05-04')
        ->and(invokePrivate($service, 'weekStartFor', ['2026-05-10']))->toBe('2026-05-04')
        ->and(invokePrivate($service, 'weekStartFor', ['2026-05-11']))->toBe('2026-05-11')
        ->and(invokePrivate($service, 'weekStartFor', [null]))->toBeNull()
        ->and(invokePrivate($service, 'weekStartFor', ['']))->toBeNull();
});

it('normalizes a fractional adoption override into a percentage', function () {
    $service = successRateService();

    // The workbook stores 0.512; the UI accepts either form.
    expect(invokePrivate($service, 'normalizeRateInput', [0.512]))->toBe(51.2)
        ->and(invokePrivate($service, 'normalizeRateInput', [51.2]))->toBe(51.2)
        ->and(invokePrivate($service, 'normalizeRateInput', ['']))->toBeNull()
        ->and(invokePrivate($service, 'normalizeRateInput', [null]))->toBeNull();
});

it('builds whole Monday-to-Sunday buckets that are never clipped to the range', function () {
    // A range starting mid-week must still yield the full week, otherwise a
    // ticket tally would be attributed to a partial bucket.
    $weeks = invokePrivate(successRateService(), 'buildWeekBuckets', [
        Carbon\Carbon::parse('2026-05-06'),
        Carbon\Carbon::parse('2026-05-19'),
    ]);

    expect($weeks)->toHaveCount(3)
        ->and($weeks[0]['start_date'])->toBe('2026-05-04')
        ->and($weeks[0]['end_date'])->toBe('2026-05-10')
        ->and($weeks[0]['week_no'])->toBe(19)
        ->and($weeks[2]['start_date'])->toBe('2026-05-18');
});

it('keeps transactions out of the hand-keyed columns', function () {
    // The save path writes exactly these; a transactions column reappearing here
    // would mean the figure became editable again.
    expect(SuccessRateWeeklyTicket::countColumns())
        ->not->toContain('order_transactions')
        ->not->toContain('mec_transactions')
        ->toContain('order_incoming')
        ->toContain('admin_closed')
        ->toHaveCount(14); // 6 modules x 2 + admin x 2
});
