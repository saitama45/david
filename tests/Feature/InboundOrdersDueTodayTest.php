<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Services\OrderReceivingService;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// The sidebar badge on Inbound Orders: deliveries due today, not received yet, in the user's stores.

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: User, 1: StoreBranch, 2: StoreBranch, 3: Supplier} */
function dueTodayFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-06 09:00', 'Asia/Manila'));

    $user = User::factory()->create();
    $mine = StoreBranch::create(['branch_code' => 'DUE-A', 'brand_code' => 'DUE-A', 'name' => 'Due Store A', 'store_status' => 'active', 'is_active' => true]);
    $other = StoreBranch::create(['branch_code' => 'DUE-B', 'brand_code' => 'DUE-B', 'name' => 'Due Store B', 'store_status' => 'active', 'is_active' => true]);
    $user->store_branches()->attach($mine->id);
    $supplier = Supplier::create(['supplier_code' => 'GSI-D', 'name' => 'GSI Due', 'is_active' => true]);

    return [$user, $mine, $other, $supplier];
}

function dueTodayOrder(User $user, StoreBranch $store, Supplier $supplier, string $status, string $date = '2026-11-06'): StoreOrder
{
    return StoreOrder::create([
        'encoder_id' => $user->id, 'supplier_id' => $supplier->id, 'store_branch_id' => $store->id,
        'order_number' => 'DUE-'.uniqid(), 'order_date' => $date, 'order_status' => $status, 'variant' => 'mass regular',
    ]);
}

it('counts today\'s deliveries of the user\'s stores that are still to receive, as the list it opens shows them', function () {
    [$user, $mine, $other, $supplier] = dueTodayFixture();
    $this->actingAs($user);

    foreach (['committed', 'approved', 'partial_committed'] as $status) {
        dueTodayOrder($user, $mine, $supplier, $status);
    }
    dueTodayOrder($user, $mine, $supplier, 'received');                  // already received
    dueTodayOrder($user, $mine, $supplier, 'pending');                   // not approved yet
    dueTodayOrder($user, $mine, $supplier, 'committed', '2026-11-07');   // tomorrow
    dueTodayOrder($user, $mine, $supplier, 'committed', '2026-11-05');   // yesterday
    dueTodayOrder($user, $other, $supplier, 'committed');                // a store not assigned to the user

    // Placed after CS Commit Required was turned on and not committed yet: not in Inbound Orders.
    $held = Supplier::create(['supplier_code' => 'GSI-H', 'name' => 'GSI Held', 'is_active' => true, 'cs_commit_required_from' => now()->subHour()]);
    dueTodayOrder($user, $mine, $held, 'approved');

    $service = app(OrderReceivingService::class);
    $due = $service->dueToday();

    expect($due)->toMatchArray(['orders' => 3, 'stores' => 1, 'date' => '2026-11-06'])
        ->and($due['listParams'])->toBe(['currentFilter' => 'commited', 'delivery_date_from' => '2026-11-06', 'delivery_date_to' => '2026-11-06']);

    // The badge opens the Inbound Orders list with exactly those parameters, and it lists the same orders.
    request()->merge($due['listParams']);
    expect($service->getOrdersList('commited')['orders']->total())->toBe(3);
});

it('counts every store for an admin, like the Inbound Orders list', function () {
    [$user, $mine, $other, $supplier] = dueTodayFixture();
    $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
    $this->actingAs($user);

    dueTodayOrder($user, $mine, $supplier, 'committed');
    dueTodayOrder($user, $other, $supplier, 'committed');

    expect(app(OrderReceivingService::class)->dueToday())->toMatchArray(['orders' => 2, 'stores' => 2]);
});

it('shares the count only with users who may open Inbound Orders', function () {
    [$user, $mine, , $supplier] = dueTodayFixture();
    dueTodayOrder($user, $mine, $supplier, 'committed');

    $shared = function (User $user) {
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        $this->actingAs($user);

        return (app(HandleInertiaRequests::class)->share($request)['inboundDueToday'])();
    };

    expect($shared($user))->toBeNull();

    Permission::firstOrCreate(['name' => 'view approved orders', 'guard_name' => 'web']);
    $user->givePermissionTo('view approved orders');

    expect($shared($user->fresh()))->toMatchArray(['orders' => 1, 'stores' => 1]);
});
