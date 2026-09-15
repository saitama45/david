<?php

use App\Http\Services\SuccessRateService;
use App\Models\Entity;
use App\Models\SuccessRateWeeklyTicket;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Support\Facades\Http;

function helpdeskWeek(string $start, string $end, array $modules = []): array
{
    $all = array_fill_keys(['order', 'commit', 'receiving', 'wastage', 'mec', 'sales_upload', 'admin'], ['incoming' => 0, 'closed' => 0]);

    return ['week_start' => $start, 'week_end' => $end, 'modules' => array_merge($all, $modules)];
}

beforeEach(function () {
    config([
        'services.helpdesk.url' => 'https://helpdesk.test',
        'services.helpdesk.key' => 'secret',
    ]);

    // Migrations seed the real entities, so reuse NONOS when it already exists.
    $entity = Entity::firstOrCreate(['code' => 'NONOS'], ['name' => "Nono's", 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);
});

it('uses helpdesk counts, sends the entity code and key, and snapshots non-zero weeks', function () {
    Http::fake([
        'helpdesk.test/api/integrations/david/ticket-tally*' => Http::response([
            'weeks' => [
                helpdeskWeek('2026-09-07', '2026-09-13'),
                helpdeskWeek('2026-09-14', '2026-09-20', [
                    'order' => ['incoming' => 3, 'closed' => 1],
                    'admin' => ['incoming' => 2, 'closed' => 2],
                ]),
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $trend = app(SuccessRateService::class)->getWeeklyTrend([
        'date_from' => '2026-09-07',
        'date_to' => '2026-09-20',
    ], $user);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Integration-Key', 'secret')
        && $request['entity'] === 'NONOS'
        && $request['date_from'] === '2026-09-07'
        && $request['date_to'] === '2026-09-20');

    $rows = collect($trend['rows'])->keyBy('week_start');

    expect($trend['ticket_source'])->toMatchArray(['mode' => 'helpdesk', 'entity_code' => 'NONOS', 'error' => null])
        ->and($rows['2026-09-14'])->toMatchArray([
            'order_incoming' => 3, 'order_closed' => 1,
            'total_tickets' => 5, 'total_closed' => 3, 'total_open' => 2,
            'close_rate' => 60.0, 'has_record' => true,
        ])
        // An all-zero live week is a real tally, but is not written to the table.
        ->and($rows['2026-09-07']['has_record'])->toBeTrue()
        ->and(SuccessRateWeeklyTicket::pluck('week_start')->map->toDateString()->all())->toBe(['2026-09-14'])
        ->and((int) SuccessRateWeeklyTicket::first()->order_incoming)->toBe(3);
});

it('falls back to the last saved counts when helpdesk fails', function () {
    $user = User::factory()->create();
    $service = app(SuccessRateService::class);
    $service->saveWeek(['week_start' => '2026-09-14', 'commit_incoming' => 4, 'commit_closed' => 1, 'remarks' => 'kept'], $user);

    Http::fake(['helpdesk.test/*' => Http::response(['message' => 'boom'], 500)]);

    $trend = $service->getWeeklyTrend(['date_from' => '2026-09-14', 'date_to' => '2026-09-20'], $user);

    expect($trend['ticket_source']['mode'])->toBe('manual')
        ->and($trend['ticket_source']['error'])->toBe('boom')
        ->and($trend['rows'][0])->toMatchArray(['commit_incoming' => 4, 'total_tickets' => 4, 'remarks' => 'kept']);
});

it('stays manual without calling helpdesk when it is not configured', function () {
    config(['services.helpdesk.key' => null]);
    Http::fake();

    $trend = app(SuccessRateService::class)->getWeeklyTrend(
        ['date_from' => '2026-09-14', 'date_to' => '2026-09-20'],
        User::factory()->create()
    );

    Http::assertNothingSent();
    expect($trend['ticket_source'])->toMatchArray(['mode' => 'manual', 'configured' => false])
        ->and($trend['rows'][0]['has_record'])->toBeFalse();
});
