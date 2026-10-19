<?php

use App\Enum\OrderStatus;
use App\Http\Controllers\MassOrdersApprovalController;
use App\Http\Controllers\OrderingTemplateApprovalController;
use App\Models\OrderedItemReceiveDate;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderApproval;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function levelApprover(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo($permissions);

    return $user;
}

/** A pending mass order of a template approved in $levels levels. */
function levelsOrder(string $supplierCode, int $levels, ?Supplier $supplier = null): array
{
    $supplier ??= Supplier::create([
        'supplier_code' => $supplierCode,
        'name' => "{$supplierCode} Supplier",
        'is_forapproval_massorders' => true,
        'mass_order_approval_levels' => $levels,
    ]);

    $branch = StoreBranch::create([
        'branch_code' => "{$supplierCode}-BR-".uniqid(),
        'name' => "{$supplierCode} Branch",
        'store_status' => 'active',
    ]);

    $order = StoreOrder::create([
        'encoder_id' => User::factory()->create()->id,
        'supplier_id' => $supplier->id,
        'store_branch_id' => $branch->id,
        'order_number' => "{$supplierCode}-LVL-".uniqid(),
        'order_date' => now()->toDateString(),
        'order_status' => OrderStatus::PENDING->value,
        'variant' => 'mass regular',
    ]);

    $item = StoreOrderItem::create([
        'store_order_id' => $order->id,
        'item_code' => "{$supplierCode}-ITEM",
        'quantity_ordered' => 10,
        'quantity_approved' => 10,
        'quantity_commited' => 0,
        'cost_per_quantity' => 5,
        'total_cost' => 50,
        'uom' => 'PCS',
    ]);

    return [$supplier, $order, $item];
}

function approveAsLevel(User $user, StoreOrder $order, StoreOrderItem $item, float $quantity)
{
    Auth::setUser($user);

    return app(MassOrdersApprovalController::class)->approve(Request::create(
        "/mass-orders-approval/approve/{$order->id}",
        'POST',
        ['items' => [['id' => $item->id, 'quantity_approved' => $quantity]]]
    ), $order->id);
}

function rejectAsLevel(User $user, StoreOrder $order)
{
    Auth::setUser($user);

    return app(MassOrdersApprovalController::class)->reject(Request::create("/mass-orders-approval/reject/{$order->id}", 'POST'), $order->id);
}

it('keeps a two-level order pending after Level 1 and approves it at Level 2', function () {
    [, $order, $item] = levelsOrder('GSI-B', 2);
    $levelOne = levelApprover(['approve mass order']);
    $levelTwo = levelApprover(['approve mass order level 2']);

    approveAsLevel($levelOne, $order, $item, 6);

    $afterLevelOne = StoreOrder::findOrFail($order->id);
    expect(session('success'))->toContain('now waits for Level 2 of 2')
        ->and($afterLevelOne->order_status)->toBe(OrderStatus::PENDING->value)
        ->and($afterLevelOne->approved_level)->toBe(1)
        ->and($afterLevelOne->approval_levels)->toBe(2)
        ->and($afterLevelOne->approver_id)->toBeNull()
        ->and((float) StoreOrderItem::findOrFail($item->id)->quantity_approved)->toBe(6.0);

    // Level 1 is done: its approver cannot approve the order again.
    expect(fn () => approveAsLevel($levelOne, $order, $item, 6))
        ->toThrow(ValidationException::class, 'waiting for its Level 2 approval');

    approveAsLevel($levelTwo, $order, $item, 5);

    $approved = StoreOrder::findOrFail($order->id);
    $steps = StoreOrderApproval::where('store_order_id', $order->id)->orderBy('id')->get();

    expect(session('success'))->toBe('Order approved successfully.')
        ->and($approved->order_status)->toBe(OrderStatus::APPROVED->value)
        ->and($approved->approved_level)->toBe(2)
        ->and((int) $approved->approver_id)->toBe($levelTwo->id)
        ->and((float) StoreOrderItem::findOrFail($item->id)->quantity_approved)->toBe(5.0)
        ->and($steps->map(fn ($step) => [$step->level, $step->action, (int) $step->user_id])->all())
        ->toBe([[1, 'approved', $levelOne->id], [2, 'approved', $levelTwo->id]]);
});

it('refuses a Level 2 approver on an order still waiting for Level 1', function () {
    [, $order, $item] = levelsOrder('GSI-B', 2);

    expect(fn () => approveAsLevel(levelApprover(['approve mass order level 2']), $order, $item, 6))
        ->toThrow(ValidationException::class, 'waiting for its Level 1 approval');

    expect(StoreOrder::findOrFail($order->id)->approved_level)->toBe(0)
        ->and(StoreOrderApproval::count())->toBe(0);
});

it('commits a two-level AO order only at its last level', function () {
    [, $order, $item] = levelsOrder('AO', 2);

    approveAsLevel(levelApprover(['approve mass order']), $order, $item, 7);

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::PENDING->value)
        ->and((float) StoreOrderItem::findOrFail($item->id)->quantity_commited)->toBe(0.0)
        ->and(OrderedItemReceiveDate::where('store_order_item_id', $item->id)->exists())->toBeFalse();

    approveAsLevel(levelApprover(['approve mass order level 2']), $order, $item, 7);

    expect(session('success'))->toBe('Order approved and committed successfully.')
        ->and(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::COMMITTED->value)
        ->and((float) StoreOrderItem::findOrFail($item->id)->quantity_commited)->toBe(7.0)
        ->and(OrderedItemReceiveDate::where('store_order_item_id', $item->id)->count())->toBe(1);
});

it('keeps the levels an order started with when its template changes, but not before its first approval', function () {
    [$supplier, $started, $startedItem] = levelsOrder('GSI-B', 2);
    [, $untouched, $untouchedItem] = levelsOrder('GSI-B', 2, $supplier);
    $levelOne = levelApprover(['approve mass order']);

    approveAsLevel($levelOne, $started, $startedItem, 6);
    $supplier->update(['mass_order_approval_levels' => 1]);

    // Already approved at Level 1 of 2: still needs Level 2.
    expect(fn () => approveAsLevel($levelOne, $started, $startedItem, 6))
        ->toThrow(ValidationException::class, 'waiting for its Level 2 approval');

    // Nobody had approved it yet: one level now finishes it.
    approveAsLevel($levelOne, $untouched, $untouchedItem, 6);

    expect(StoreOrder::findOrFail($untouched->id)->order_status)->toBe(OrderStatus::APPROVED->value)
        ->and(StoreOrder::findOrFail($untouched->id)->approval_levels)->toBe(1);
});

it('rejects at Level 2 only with that level\'s reject permission', function () {
    [, $order, $item] = levelsOrder('GSI-B', 2);
    approveAsLevel(levelApprover(['approve mass order']), $order, $item, 6);

    expect(fn () => rejectAsLevel(levelApprover(['reject mass order']), $order))
        ->toThrow(ValidationException::class, 'waiting for its Level 2 approval');

    $rejecter = levelApprover(['reject mass order level 2']);
    rejectAsLevel($rejecter, $order);

    $step = StoreOrderApproval::where('store_order_id', $order->id)->latest('id')->first();

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::REJECTED->value)
        ->and([$step->level, $step->action, (int) $step->user_id])->toBe([2, 'rejected', $rejecter->id]);
});

it('lists one tab per level and files each pending order under the level it waits for', function () {
    [$supplier, $waitingOne] = levelsOrder('GSI-B', 2);
    [, $waitingTwo, $item] = levelsOrder('GSI-B', 2, $supplier);
    approveAsLevel(levelApprover(['approve mass order']), $waitingTwo, $item, 6);

    // Sees every store of both orders.
    $viewer = levelApprover(['view mass order approval', 'approve mass order level 2']);
    $viewer->store_branches()->attach([$waitingOne->store_branch_id, $waitingTwo->store_branch_id]);
    Auth::setUser($viewer);

    $props = fn (array $query) => app(MassOrdersApprovalController::class)
        ->index(Request::create('/mass-orders-approval', 'GET', $query))
        ->toResponse(Request::create('/mass-orders-approval'))->getOriginalContent()->getData()['page']['props'];

    // A Level 2 approver lands on the level they decide.
    $default = $props([]);
    $levelOne = $props(['filter' => 'level_1']);

    expect($default['approvalLevels'])->toBe(2)
        ->and($default['filters']['currentFilter'])->toBe('level_2')
        ->and(collect($default['orders']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$waitingTwo->id])
        ->and($default['orders']['data'][0]['approval_progress'])->toBe(['level' => 2, 'of' => 2])
        ->and($default['counts'])->toMatchArray(['pending' => 2, 'level_1' => 1, 'level_2' => 1])
        ->and(collect($levelOne['orders']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$waitingOne->id]);
});

it('saves a template\'s approval levels and refuses more than three', function () {
    [$supplier] = levelsOrder('GSI-B', 1);
    Auth::setUser(levelApprover(['edit ordering template approval']));
    $controller = app(OrderingTemplateApprovalController::class);

    $controller->update(Request::create('/', 'POST', ['is_forapproval_massorders' => true, 'mass_order_approval_levels' => 3]), $supplier);

    expect(Supplier::findOrFail($supplier->id)->mass_order_approval_levels)->toBe(3);

    expect(fn () => $controller->update(Request::create('/', 'POST', ['is_forapproval_massorders' => true, 'mass_order_approval_levels' => 4]), $supplier))
        ->toThrow(ValidationException::class);

    expect(Supplier::findOrFail($supplier->id)->mass_order_approval_levels)->toBe(3);
});
