<?php

use App\Enums\RuleExceptionStatus;
use App\Http\Controllers\MassOrdersController;
use App\Http\Requests\StoreOrder\UpdateOrderRequest;
use App\Http\Services\RuleExceptionService;
use App\Models\Entity;
use App\Models\OrdersCutoff;
use App\Models\RuleExceptionRequest;
use App\Models\RuleExceptionRequestAction;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Business-rule exception lifecycle, driven through mass_order.edit_after_cutoff.
 *
 * Runs on the isolated daviddb_test database (phpunit.xml forces it). Nothing
 * here deletes or soft-deletes a record.
 *
 * Fixture timeline (Asia/Manila): the order is placed Tue 2026-09-15 10:00, so
 * its edit cutoff is GSI-P's Wed 2026-09-16 08:00. "Now" is Wed 09:00 - locked.
 */
function exceptionFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-16 09:00', 'Asia/Manila'));

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    foreach (['edit mass orders', 'approve mass order', 'view rule exception log'] as $name) {
        Permission::findOrCreate($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $store = StoreBranch::create(['branch_code' => 'TST', 'brand_code' => 'TST', 'name' => 'Test Store', 'store_status' => 'Active', 'is_active' => 1]);
    $otherStore = StoreBranch::create(['branch_code' => 'OTH', 'brand_code' => 'OTH', 'name' => 'Other Store', 'store_status' => 'Active', 'is_active' => 1]);
    $supplier = Supplier::create(['supplier_code' => 'GSI-P', 'name' => 'GSI Produce', 'is_active' => true]);

    OrdersCutoff::create([
        'ordering_template' => 'GSI-P',
        'cutoff_1_day' => 3, 'cutoff_1_time' => '08:00', 'days_covered_1' => 'Mon,Tue,Wed',
        'cutoff_2_day' => 5, 'cutoff_2_time' => '08:15', 'days_covered_2' => 'Thu,Fri,Sat',
    ]);

    $requester = User::factory()->create();
    $requester->givePermissionTo('edit mass orders');
    $approver = User::factory()->create();
    $approver->givePermissionTo('approve mass order');
    $outsider = User::factory()->create();
    $outsider->givePermissionTo('approve mass order');

    UserAssignedStoreBranch::create(['user_id' => $requester->id, 'store_branch_id' => $store->id]);
    UserAssignedStoreBranch::create(['user_id' => $approver->id, 'store_branch_id' => $store->id]);
    UserAssignedStoreBranch::create(['user_id' => $outsider->id, 'store_branch_id' => $otherStore->id]);

    $order = StoreOrder::create([
        'encoder_id' => $requester->id,
        'supplier_id' => $supplier->id,
        'store_branch_id' => $store->id,
        'order_number' => 'TST-'.uniqid(),
        'order_date' => '2026-09-24',
        'order_status' => 'approved',
        'variant' => 'mass regular',
    ]);
    $order->forceFill(['created_at' => '2026-09-15 10:00:00'])->saveQuietly();

    $item = StoreOrderItem::create([
        'store_order_id' => $order->id,
        'item_code' => 'ITEM-1',
        'quantity_ordered' => 5,
        'quantity_approved' => 5,
        'quantity_commited' => 5,
        'cost_per_quantity' => 10,
        'total_cost' => 50,
        'uom' => 'PCS',
    ]);

    return compact('entity', 'store', 'supplier', 'requester', 'approver', 'outsider', 'order', 'item');
}

function submitEditException(User $user, StoreOrder $order): RuleExceptionRequest
{
    return app(RuleExceptionService::class)->submit(
        $user,
        ['rule_key' => 'mass_order.edit_after_cutoff', 'reason_code' => 'supplier_delivery', 'justification' => 'Supplier shorted two items, quantities must be corrected.'],
        ['order_number' => $order->order_number],
    );
}

function updateMassOrderAs(User $user, StoreOrder $order, StoreOrderItem $item, int $quantity)
{
    test()->actingAs($user);

    $request = UpdateOrderRequest::create("/mass-orders/update/{$order->order_number}", 'PUT', [
        'supplier_id' => 'GSI-P',
        'branch_id' => $order->store_branch_id,
        'order_date' => '2026-09-24',
        'orders' => [[
            'id' => $item->id,
            'inventory_code' => 'ITEM-1',
            'quantity' => $quantity,
            'cost' => 10,
            'total_cost' => $quantity * 10,
            'unit_of_measurement' => 'PCS',
        ]],
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => $user);
    $request->validateResolved();

    return app(MassOrdersController::class)->update($request, $order->order_number);
}

afterEach(fn () => Carbon::setTestNow());

it('runs a request through submit, approve and single consumption with a full audit trail', function () {
    ['requester' => $requester, 'approver' => $approver, 'outsider' => $outsider, 'order' => $order] = exceptionFixture();
    $service = app(RuleExceptionService::class);

    $request = submitEditException($requester, $order);
    expect($request->status)->toBe(RuleExceptionStatus::PENDING);

    // Segregation of duties and store scope.
    expect(fn () => $service->approve($requester, $request, null, null))->toThrow(HttpException::class);
    expect(fn () => $service->approve($outsider, $request, null, null))->toThrow(HttpException::class);

    $approved = $service->approve($approver, $request, null, 'Confirmed with supplier.');
    expect($approved->status)->toBe(RuleExceptionStatus::APPROVED)
        ->and($approved->valid_until->format('Y-m-d H:i'))->toBe('2026-09-16 21:00'); // default 12h, inside the delivery-date cap

    DB::transaction(fn () => $service->consume('mass_order.edit_after_cutoff', $order->order_number, $requester, 'store_order', $order->order_number));

    expect(RuleExceptionRequest::find($request->id)->status)->toBe(RuleExceptionStatus::CONSUMED);
    expect(fn () => DB::transaction(fn () => $service->consume('mass_order.edit_after_cutoff', $order->order_number, $requester, 'store_order', $order->order_number)))
        ->toThrow(ValidationException::class);

    expect(RuleExceptionRequestAction::where('rule_exception_request_id', $request->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['submitted', 'approved', 'consumed']);
});

it('refuses duplicate, unnecessary and over-long exceptions', function () {
    ['requester' => $requester, 'approver' => $approver, 'order' => $order] = exceptionFixture();
    $service = app(RuleExceptionService::class);

    $request = submitEditException($requester, $order);
    expect(fn () => submitEditException($requester, $order))->toThrow(ValidationException::class);

    // Validity cannot run past the start of the delivery day.
    expect(fn () => $service->approve($approver, $request, '2026-09-25 09:00', null))->toThrow(ValidationException::class);

    // Before the edit cutoff nothing is blocked, so nothing may be requested.
    Carbon::setTestNow(Carbon::parse('2026-09-15 11:00', 'Asia/Manila'));
    $service->cancel($requester, $request);
    expect(fn () => submitEditException($requester, $order))->toThrow(ValidationException::class);
});

it('expires an unused grant, which can then no longer be consumed', function () {
    ['requester' => $requester, 'approver' => $approver, 'order' => $order] = exceptionFixture();
    $service = app(RuleExceptionService::class);

    $request = submitEditException($requester, $order);
    $service->approve($approver, $request, '2026-09-16 10:00', null);

    Carbon::setTestNow(Carbon::parse('2026-09-16 10:30', 'Asia/Manila'));

    expect(fn () => DB::transaction(fn () => $service->consume('mass_order.edit_after_cutoff', $order->order_number, $requester, 'store_order', $order->order_number)))
        ->toThrow(ValidationException::class)
        ->and($service->expireStale())->toBe(1)
        ->and(RuleExceptionRequest::find($request->id)->status)->toBe(RuleExceptionStatus::EXPIRED);
});

it('enforces the mass order edit cutoff on the server and lets one approved edit through', function () {
    ['requester' => $requester, 'approver' => $approver, 'order' => $order, 'item' => $item] = exceptionFixture();

    // Past the cutoff with no exception: refused, nothing changes.
    $response = updateMassOrderAs($requester, $order, $item, 9);
    expect(session('errors')->first('error'))->toContain('edit cutoff for this order passed')
        ->and((float) StoreOrderItem::find($item->id)->quantity_ordered)->toBe(5.0);

    // With an approved exception the edit goes through once and consumes it.
    $request = submitEditException($requester, $order);
    app(RuleExceptionService::class)->approve($approver, $request, null, null);

    $response = updateMassOrderAs($requester, $order, $item, 9);
    expect($response->getTargetUrl())->toBe(route('mass-orders.index'))
        ->and((float) StoreOrderItem::find($item->id)->quantity_ordered)->toBe(9.0)
        ->and(RuleExceptionRequest::find($request->id)->status)->toBe(RuleExceptionStatus::CONSUMED);

    // And the order is locked again.
    session()->forget('errors');
    updateMassOrderAs($requester, $order, $item, 12);
    expect(session('errors')->first('error'))->toContain('edit cutoff for this order passed')
        ->and((float) StoreOrderItem::find($item->id)->quantity_ordered)->toBe(9.0);
});
