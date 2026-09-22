<?php

use App\Enums\RuleExceptionStatus;
use App\Http\Services\OrderingCutoffService;
use App\Http\Services\RuleExceptions\AdoptionExcuseEvaluator;
use Carbon\Carbon;

/**
 * Pins the ordering cutoff rules that are now enforced on the server. Cutoff
 * rows are plain objects, so nothing here touches the database.
 */
function cutoffRow(array $values): object
{
    return (object) array_merge([
        'cutoff_1_day' => null, 'cutoff_1_time' => null, 'days_covered_1' => null,
        'cutoff_2_day' => null, 'cutoff_2_time' => null, 'days_covered_2' => null,
    ], $values);
}

function manila(string $moment): Carbon
{
    return Carbon::parse($moment, 'Asia/Manila');
}

// GSI-P in daviddb: Wed 08:00 covers Mon-Wed, Fri 08:15 covers Thu-Sat.
$gsi = fn () => cutoffRow([
    'cutoff_1_day' => 3, 'cutoff_1_time' => '08:00:00.0000000', 'days_covered_1' => 'Mon,Tue,Wed',
    'cutoff_2_day' => 5, 'cutoff_2_time' => '08:15:00.0000000', 'days_covered_2' => 'Thu,Fri,Sat',
]);

test('mass order dates switch at the exact cutoff minute and GSI is shifted a week', function () use ($gsi) {
    $service = new OrderingCutoffService;

    // Week of Sun 2026-09-13. One minute before cutoff 1: next week's Mon-Wed.
    expect($service->massOrderDatesFor($gsi(), 'GSI-P', manila('2026-09-16 07:59')))
        ->toBe(['2026-09-21', '2026-09-22', '2026-09-23']);

    // At cutoff 1 the first window is gone: next week's Thu-Sat.
    expect($service->massOrderDatesFor($gsi(), 'GSI-P', manila('2026-09-16 08:00')))
        ->toBe(['2026-09-24', '2026-09-25', '2026-09-26']);

    // After both cutoffs: the week after next.
    expect($service->massOrderDatesFor($gsi(), 'GSI-P', manila('2026-09-18 08:15')))
        ->toBe(['2026-09-28', '2026-09-29', '2026-09-30']);

    // A template without the GSI shift orders for the current week.
    expect($service->massOrderDatesFor($gsi(), 'ICE CREAM', manila('2026-09-16 07:59')))
        ->toBe(['2026-09-14', '2026-09-15', '2026-09-16']);
});

test('CPO has no delivery date restriction', function () {
    expect((new OrderingCutoffService)->massOrderDateBlock('CPO', '2020-01-01'))->toBeNull();
});

test('a mass order template with no cutoff row is open from tomorrow for 60 days', function () {
    $service = new class extends OrderingCutoffService
    {
        public function cutoffFor(string $orderingTemplate): ?\App\Models\OrdersCutoff
        {
            return null;
        }
    };
    $now = manila('2026-09-22 23:30');

    $dates = $service->massOrderAvailableDates('DTSP', $now);

    expect($dates)->toHaveCount(60)
        ->and($dates[0])->toBe('2026-09-23')
        ->and(end($dates))->toBe('2026-11-21')
        ->and($service->massOrderDateBlock('DTSP', '2026-10-15', $now))->toBeNull()
        ->and($service->massOrderDateBlock('DTSP', '2026-09-22', $now))->not->toBeNull();
});

test('mass order edit deadline is the first cutoff after placement', function () use ($gsi) {
    $service = new OrderingCutoffService;

    expect($service->editDeadlineFor($gsi(), manila('2026-09-15 10:00'))->format('Y-m-d H:i'))->toBe('2026-09-16 08:00')
        ->and($service->editDeadlineFor($gsi(), manila('2026-09-16 08:00'))->format('Y-m-d H:i'))->toBe('2026-09-18 08:15')
        ->and($service->editDeadlineFor($gsi(), manila('2026-09-19 09:00'))->format('Y-m-d H:i'))->toBe('2026-09-23 08:00')
        ->and($service->editDeadlineFor(cutoffRow([]), manila('2026-09-15 10:00')))->toBeNull();
});

test('a Sunday cutoff (day 7) locks the following Sunday, not a moment already past', function () {
    $sunday = cutoffRow(['cutoff_1_day' => 7, 'cutoff_1_time' => '09:00', 'days_covered_1' => 'Mon']);

    // Placed Sunday 10:00, after that day's 09:00 cutoff. The old browser check
    // compared raw day 7 with getUTCDay() 0 and returned 09:00 the same day.
    expect((new OrderingCutoffService)->editDeadlineFor($sunday, manila('2026-09-20 10:00'))->format('Y-m-d H:i'))
        ->toBe('2026-09-27 09:00');
});

test('DTS dates: a single weekly cutoff orders next week, a split cutoff this week', function () use ($gsi) {
    $service = new OrderingCutoffService;
    $iceCream = cutoffRow(['cutoff_1_day' => 3, 'cutoff_1_time' => '12:00', 'days_covered_1' => 'Mon,Tue,Wed,Thu,Fri', 'cutoff_2_time' => '12:01']);

    expect($service->dtsDatesFor($iceCream, manila('2026-09-16 11:59')))
        ->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'])
        ->and($service->dtsDatesFor($gsi(), manila('2026-09-16 07:59')))
        ->toBe(['2026-09-14', '2026-09-15', '2026-09-16'])
        ->and($service->dtsDatesFor($gsi(), manila('2026-09-17 09:00')))
        ->toBe(['2026-09-17', '2026-09-18', '2026-09-19']);
});

test('DTS batch edit lock follows the batch week and cutoffs', function () use ($gsi) {
    $service = new OrderingCutoffService;
    $now = manila('2026-09-16 09:00'); // past cutoff 1, before cutoff 2

    expect($service->dtsBatchEditLockedFor($gsi(), '2026-09-07', $now))->toBeTrue()   // past week
        ->and($service->dtsBatchEditLockedFor($gsi(), '2026-09-21', $now))->toBeFalse() // future week
        ->and($service->dtsBatchEditLockedFor($gsi(), '2026-09-14', $now))->toBeTrue()  // Mon: days_covered_1, cutoff 1 passed
        ->and($service->dtsBatchEditLockedFor($gsi(), '2026-09-17', $now))->toBeFalse() // Thu: still before cutoff 2
        ->and($service->dtsBatchEditLockedFor($gsi(), '2026-09-17', manila('2026-09-18 08:15')))->toBeTrue()
        ->and($service->dtsBatchEditLockedFor(null, '2026-09-07', $now))->toBeFalse();
});

test('exception status transitions allow only the defined lifecycle', function () {
    expect(RuleExceptionStatus::PENDING->canTransitionTo(RuleExceptionStatus::APPROVED, 'unlock'))->toBeTrue()
        ->and(RuleExceptionStatus::PENDING->canTransitionTo(RuleExceptionStatus::CONSUMED, 'unlock'))->toBeFalse()
        ->and(RuleExceptionStatus::APPROVED->canTransitionTo(RuleExceptionStatus::CONSUMED, 'unlock'))->toBeTrue()
        ->and(RuleExceptionStatus::APPROVED->canTransitionTo(RuleExceptionStatus::CONSUMED, 'excuse'))->toBeFalse()
        ->and(RuleExceptionStatus::REJECTED->canTransitionTo(RuleExceptionStatus::APPROVED, 'unlock'))->toBeFalse()
        ->and(RuleExceptionStatus::CONSUMED->canTransitionTo(RuleExceptionStatus::EXPIRED, 'unlock'))->toBeFalse();
});

test('adoption row keys parse to store and date', function () {
    expect(AdoptionExcuseEvaluator::parseRowKey('SALES_UPLOAD|15|2026-09-14'))->toBe([15, '2026-09-14'])
        ->and(AdoptionExcuseEvaluator::parseRowKey('WASTAGE_UPLOAD:88|8|2026-09-10'))->toBe([8, '2026-09-10'])
        ->and(AdoptionExcuseEvaluator::parseRowKey('GSI-P|abc|2026-09-10'))->toBeNull()
        ->and(AdoptionExcuseEvaluator::parseRowKey('nonsense'))->toBeNull();
});
