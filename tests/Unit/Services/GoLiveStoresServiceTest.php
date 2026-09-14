<?php

use App\Http\Services\GoLiveStoresService;

/**
 * buildRows()/buildTotals() are pure functions of the week buckets, the store
 * list and each store's first ordering-transaction date, so they are exercised
 * directly without the database.
 */
function goLiveInvoke(string $method, array $args)
{
    $service = new GoLiveStoresService;
    $reflection = new ReflectionMethod(GoLiveStoresService::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($service, $args);
}

// The service reads only id and name, so a plain object stands in for StoreBranch.
function goLiveStore(int $id, string $name): object
{
    return (object) ['id' => $id, 'name' => $name];
}

test('a store goes live in the week of its first ordering transaction and stays live', function () {
    $weeks = goLiveInvoke('buildWeekBuckets', [
        Carbon\Carbon::parse('2026-05-04'),
        Carbon\Carbon::parse('2026-05-24')->endOfDay(),
    ]);

    expect(array_column($weeks, 'week_no'))->toBe([19, 20, 21]);

    $stores = collect([goLiveStore(1, 'Alpha'), goLiveStore(2, 'Bravo'), goLiveStore(3, 'Charlie'), goLiveStore(4, 'Delta')]);
    $goLiveDates = [
        1 => '2026-01-10', // live before the range
        2 => '2026-05-10', // Sunday of week 19
        3 => '2026-05-18', // Monday of week 21
    ];

    $rows = goLiveInvoke('buildRows', [$weeks, $stores, $goLiveDates]);

    expect(array_column($rows, 'live_stores'))->toBe([2, 2, 3])
        ->and(array_column($rows, 'new_go_live'))->toBe([1, 0, 1])
        ->and($rows[0]['new_stores'])->toBe(['Bravo'])
        ->and(array_column($rows, 'not_live_stores'))->toBe([2, 2, 1])
        ->and($rows[2]['go_live_rate'])->toBe(75.0)
        ->and($rows[0]['week_label'])->toBe('Week 19');

    $totals = goLiveInvoke('buildTotals', [$rows, $stores, $goLiveDates]);

    expect($totals['live_stores'])->toBe(3)
        ->and($totals['new_in_range'])->toBe(2)
        ->and($totals['not_live_stores'])->toBe(['Delta']);
});
