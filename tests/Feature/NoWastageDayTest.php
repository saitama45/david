<?php

use App\Enums\WastageStatus;
use App\Http\Controllers\NoWastageDayController;
use App\Http\Controllers\WastageController;
use App\Http\Services\WastageService;
use App\Models\Entity;
use App\Models\NoWastageDay;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Models\Wastage;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * "No Wastage for the Day": a store states it had no wastage on a date, which accounting sees on
 * the Wastage Records page. A day is one or the other: no mark while the store has a wastage record
 * that day, and no wastage record for a marked day until it is unmarked.
 *
 * Controllers and services are called directly: every test HTTP request disconnects the database
 * on terminate(), which rolls back RefreshDatabase's transaction. Nothing here deletes a record.
 */
function noWastageFixture(): array
{
    $entity = Entity::create(['name' => 'TC', 'code' => 'TC'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $store = StoreBranch::create([
        'branch_code' => 'TNW', 'brand_code' => 'TNW', 'name' => 'No Wastage Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);
    $otherStore = StoreBranch::create([
        'branch_code' => 'TNX', 'brand_code' => 'TNX', 'name' => 'Not Assigned Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);
    UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);

    foreach (['view wastage record', 'create wastage record', 'edit wastage record', 'delete wastage record', 'export wastage record', 'view cost wastage record'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $user->givePermissionTo(['view wastage record', 'create wastage record']);
    test()->actingAs($user);

    return compact('entity', 'user', 'store', 'otherStore');
}

function noWastageDeclare(StoreBranch $store, string $date): array
{
    try {
        app(NoWastageDayController::class)->store(Request::create('/wastage/no-wastage-day', 'POST', [
            'store_branch_id' => $store->id, 'wastage_date' => $date,
        ])->setUserResolver(fn () => auth()->user()));
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function noWastageToday(): string
{
    return Carbon::now('Asia/Manila')->toDateString();
}

it('marks a day with no wastage and lists it on the Wastage Records page', function () {
    $f = noWastageFixture();

    expect(noWastageDeclare($f['store'], noWastageToday()))->toBe([]);

    $day = NoWastageDay::firstOrFail();
    expect($day->store_branch_id)->toEqual($f['store']->id)
        ->and($day->wastage_date->toDateString())->toBe(noWastageToday())
        ->and((int) $day->declared_by)->toBe($f['user']->id)
        ->and((int) $day->entity_id)->toBe($f['entity']->id);

    $props = app(WastageController::class)->index(Request::create('/wastage', 'GET', ['tab' => 'no_wastage']))
        ->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props'];

    expect($props['noWastageCount'])->toBe(1)
        ->and($props['noWastageDays']['data'])->toHaveCount(1)
        ->and($props['noWastageDays']['data'][0])->toMatchArray([
            'store' => 'No Wastage Store (TNW)', 'wastage_date' => noWastageToday(),
        ]);
});

it('refuses a day already marked, a future date and a store the user is not assigned to', function () {
    $f = noWastageFixture();
    noWastageDeclare($f['store'], noWastageToday());

    expect(noWastageDeclare($f['store'], noWastageToday())['wastage_date'][0] ?? '')->toContain('already marked No Wastage')
        ->and(noWastageDeclare($f['store'], Carbon::now('Asia/Manila')->addDay()->toDateString())['wastage_date'][0] ?? '')
        ->toBe('No Wastage cannot be recorded for a future date.')
        ->and(noWastageDeclare($f['otherStore'], noWastageToday())['store_branch_id'][0] ?? '')
        ->toBe('You are not assigned to this store.')
        ->and(NoWastageDay::count())->toBe(1);
});

it('refuses a day the store has a wastage record on, unless that record was cancelled', function () {
    $f = noWastageFixture();
    $wastage = Wastage::create([
        'store_branch_id' => $f['store']->id, 'wastage_no' => 'WS-NW-1', 'wastage_date' => noWastageToday(),
        'wastage_qty' => 1, 'cost' => 1, 'reason' => 'Spoilage', 'wastage_status' => WastageStatus::PENDING->value, 'created_by' => $f['user']->id,
    ]);

    expect(noWastageDeclare($f['store'], noWastageToday())['wastage_date'][0] ?? '')
        ->toContain('already has a wastage record dated');

    $wastage->update(['wastage_status' => WastageStatus::CANCELLED->value]);

    expect(noWastageDeclare($f['store'], noWastageToday()))->toBe([]);
});

function noWastageUnmark(StoreBranch $store, string $date): array
{
    try {
        app(NoWastageDayController::class)->unmark(Request::create('/wastage/no-wastage-day/unmark', 'POST', [
            'store_branch_id' => $store->id, 'wastage_date' => $date,
        ])->setUserResolver(fn () => auth()->user()));
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function noWastageRecord(array $f): \Illuminate\Support\Collection
{
    return app(WastageService::class)->createMultipleWastageRecords([
        'store_branch_id' => $f['store']->id, 'wastage_date' => noWastageToday(), 'remarks' => 'Found spoiled milk',
        'cartItems' => [['sap_masterfile_id' => null, 'quantity' => 1, 'cost' => 1, 'reason' => 'Spoilage']],
    ], $f['user']->id);
}

it('takes no wastage record for a marked day, on the form or in the service', function () {
    $f = noWastageFixture();
    noWastageDeclare($f['store'], noWastageToday());

    $request = \App\Http\Requests\WastageRequest::create('/wastage', 'POST', [
        'store_branch_id' => $f['store']->id, 'wastage_date' => noWastageToday(), 'remarks' => 'Test', 'cartItems' => [],
    ]);
    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $request->rules());

    expect($validator->errors()->get('wastage_date')[0] ?? '')
        ->toBe('This store is marked No Wastage for ' . Carbon::now('Asia/Manila')->format('M j, Y') . '. Unmark the day on Create Wastage Record to record wastage.')
        ->and(fn () => noWastageRecord($f))->toThrow(Exception::class, 'is marked No Wastage')
        ->and(Wastage::count())->toBe(0)
        ->and(NoWastageDay::standing()->count())->toBe(1);
});

it('unmarks a day marked by mistake, keeping the row, so wastage can be recorded', function () {
    $f = noWastageFixture();
    noWastageDeclare($f['store'], noWastageToday());

    expect(noWastageUnmark($f['store'], noWastageToday()))->toBe([]);

    $day = NoWastageDay::firstOrFail();
    expect($day->withdrawn_at)->not->toBeNull()
        ->and((int) $day->withdrawn_by)->toBe($f['user']->id)
        ->and(NoWastageDay::standing()->count())->toBe(0);

    $records = noWastageRecord($f);

    // Status on the Create page: no mark, and the record is why the day cannot be marked again.
    $status = app(NoWastageDayController::class)->status(Request::create('/wastage/no-wastage-day/status', 'GET', [
        'store_branch_id' => $f['store']->id, 'wastage_date' => noWastageToday(),
    ])->setUserResolver(fn () => auth()->user()))->getData(true);

    expect($records)->toHaveCount(1)
        ->and($status['declared'])->toBeNull()
        ->and($status['wastage_numbers'])->toBe([$records->first()->wastage_no])
        ->and($status['problem'])->toContain('already has a wastage record dated');

    // Once that record is cancelled, the same row is marked again.
    $records->each->update(['wastage_status' => WastageStatus::CANCELLED->value]);

    expect(noWastageDeclare($f['store'], noWastageToday()))->toBe([])
        ->and(NoWastageDay::count())->toBe(1)
        ->and(NoWastageDay::standing()->count())->toBe(1);
});

it('refuses to unmark a day that is not marked, or a store the user is not assigned to', function () {
    $f = noWastageFixture();

    expect(noWastageUnmark($f['store'], noWastageToday())['wastage_date'][0] ?? '')->toBe('This day is not marked No Wastage.')
        ->and(noWastageUnmark($f['otherStore'], noWastageToday())['store_branch_id'][0] ?? '')->toBe('You are not assigned to this store.');
});

function noWastageEditProps(NoWastageDay $day): array
{
    return app(NoWastageDayController::class)
        ->edit(Request::create("/wastage/no-wastage-day/{$day->id}/edit")->setUserResolver(fn () => auth()->user()), $day)
        ->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props'];
}

it('opens a marked day with Edit, and after Unmark shows who unmarked it', function () {
    $f = noWastageFixture();
    noWastageDeclare($f['store'], noWastageToday());
    $day = NoWastageDay::firstOrFail();

    $props = noWastageEditProps($day);
    expect($props['day'])->toMatchArray([
        'store' => 'No Wastage Store (TNW)', 'wastage_date' => noWastageToday(), 'marked' => true, 'unmarked_by' => null,
    ])->and($props['unmarkProblem'])->toBeNull();

    noWastageUnmark($f['store'], noWastageToday());

    $props = noWastageEditProps($day->fresh());
    expect($props['day']['marked'])->toBeFalse()
        ->and($props['day']['unmarked_at'])->not->toBeNull()
        ->and($props['day']['unmarked_by'])->toBe(trim($f['user']->first_name . ' ' . $f['user']->last_name) ?: 'Unknown user');
});

it('refuses the Edit page of a day of a store the user is not assigned to', function () {
    $f = noWastageFixture();
    $day = NoWastageDay::create([
        'store_branch_id' => $f['otherStore']->id, 'wastage_date' => noWastageToday(),
        'declared_by' => $f['user']->id, 'declared_at' => now('Asia/Manila'),
    ]);

    expect(fn () => noWastageEditProps($day))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('fills in the store and date on Create Wastage Record from an unmarked day, never another store or a future date', function () {
    $f = noWastageFixture();
    $prefill = function (array $query) {
        app()->instance('request', Request::create('/wastage/create', 'GET', $query)->setUserResolver(fn () => auth()->user()));

        return app(WastageController::class)->create()
            ->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props']['prefill'];
    };

    expect($prefill(['store_branch_id' => $f['store']->id, 'wastage_date' => noWastageToday()]))
        ->toBe(['store_branch_id' => $f['store']->id, 'wastage_date' => noWastageToday()])
        ->and($prefill(['store_branch_id' => $f['otherStore']->id, 'wastage_date' => Carbon::now('Asia/Manila')->addDay()->toDateString()]))
        ->toBe(['store_branch_id' => null, 'wastage_date' => null]);
});
