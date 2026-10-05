<?php

use App\Enum\OrderStatus;
use App\Http\Controllers\MassOrdersApprovalController;
use App\Models\OrderedItemReceiveDate;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;

function createMassOrderApprovalOrder(string $supplierCode, float $committedQuantity = 4): array
{
    $user = User::factory()->create();

    $supplier = Supplier::create([
        'supplier_code' => $supplierCode,
        'name' => "{$supplierCode} Supplier",
        'is_forapproval_massorders' => true,
    ]);

    $branch = StoreBranch::create([
        'branch_code' => "{$supplierCode}-BR",
        'name' => "{$supplierCode} Branch",
        'store_status' => 'active',
    ]);

    $order = StoreOrder::create([
        'encoder_id' => $user->id,
        'supplier_id' => $supplier->id,
        'store_branch_id' => $branch->id,
        'order_number' => "{$supplierCode}-ORDER-" . uniqid(),
        'order_date' => now()->toDateString(),
        'order_status' => OrderStatus::PENDING->value,
        'variant' => 'mass regular',
    ]);

    $item = StoreOrderItem::create([
        'store_order_id' => $order->id,
        'item_code' => "{$supplierCode}-ITEM",
        'quantity_ordered' => 10,
        'quantity_approved' => 4,
        'quantity_commited' => $committedQuantity,
        'cost_per_quantity' => 5,
        'total_cost' => 50,
        'uom' => 'PCS',
    ]);

    return [$user, $order, $item];
}

function approveMassOrderThroughController(StoreOrder $order, StoreOrderItem $item, float $quantity)
{
    $request = Request::create(
        "/mass-orders-approval/approve/{$order->id}",
        'POST',
        [
            'items' => [
                ['id' => $item->id, 'quantity_approved' => $quantity],
            ],
        ]
    );
    return app(MassOrdersApprovalController::class)->approve($request, $order->id);
}

it('commits CPO mass orders when approved', function () {
    [$user, $order, $item] = createMassOrderApprovalOrder('CPO');

    $this->actingAs($user);

    $response = approveMassOrderThroughController($order, $item, 7);

    expect($response->getTargetUrl())->toBe(route('mass-orders-approval.index'))
        ->and(session('success'))->toBe('Order approved and committed successfully.');

    $order = StoreOrder::findOrFail($order->id);
    $item = StoreOrderItem::findOrFail($item->id);
    $receiveRow = OrderedItemReceiveDate::where('store_order_item_id', $item->id)->first();

    expect($order->order_status)->toBe(OrderStatus::COMMITTED->value)
        ->and((int) $order->approver_id)->toBe($user->id)
        ->and((int) $order->commiter_id)->toBe($user->id)
        ->and($order->approval_action_date)->not->toBeNull()
        ->and($order->commited_action_date)->not->toBeNull()
        ->and((float) $item->quantity_approved)->toBe(7.0)
        ->and((float) $item->quantity_commited)->toBe(7.0)
        ->and((int) $item->committed_by)->toBe($user->id)
        ->and($item->committed_date)->not->toBeNull()
        ->and($receiveRow)->not->toBeNull()
        ->and((float) $receiveRow->quantity_received)->toBe(7.0)
        ->and($receiveRow->status)->toBe('pending')
        ->and((int) $receiveRow->received_by_user_id)->toBe($user->id)
        ->and($receiveRow->received_date)->toBeNull()
        ->and($receiveRow->remarks)->toBeNull();
});

it('keeps non-CPO mass orders approved without creating receiving rows', function () {
    [$user, $order, $item] = createMassOrderApprovalOrder('GSI-B', committedQuantity: 4);

    $this->actingAs($user);

    $response = approveMassOrderThroughController($order, $item, 6);

    expect($response->getTargetUrl())->toBe(route('mass-orders-approval.index'))
        ->and(session('success'))->toBe('Order approved successfully.');

    $order = StoreOrder::findOrFail($order->id);
    $item = StoreOrderItem::findOrFail($item->id);

    expect($order->order_status)->toBe(OrderStatus::APPROVED->value)
        ->and((float) $item->quantity_approved)->toBe(6.0)
        ->and((float) $item->quantity_commited)->toBe(4.0)
        ->and(OrderedItemReceiveDate::where('store_order_item_id', $item->id)->exists())->toBeFalse();
});

/**
 * The order as a store leaves it after receiving: its line posted, the order received and,
 * with $finalized, locked by Final Receive All.
 */
function receiveMassOrderApprovalOrder(User $user, StoreOrder $order, StoreOrderItem $item, bool $finalized = true): void
{
    OrderedItemReceiveDate::create([
        'store_order_item_id' => $item->id, 'received_by_user_id' => $user->id, 'approval_action_by' => $user->id,
        'quantity_received' => 4, 'received_date' => now('Asia/Manila')->format('Y-m-d H:i:s'),
        'remarks' => 'Received', 'status' => 'approved',
    ]);

    // Not fillable: stamped by a query update, as Final Receive All does.
    StoreOrder::whereKey($order->id)->update([
        'order_status' => OrderStatus::RECEIVED->value,
        'receiving_finalized_at' => $finalized ? now('Asia/Manila')->format('Y-m-d H:i:s') : null,
        'receiving_finalized_by' => $finalized ? $user->id : null,
    ]);
}

it('refuses to approve a received CPO order again, and adds no second set of receiving rows', function (bool $finalized) {
    [$user, $order, $item] = createMassOrderApprovalOrder('CPO');
    $this->actingAs($user);

    approveMassOrderThroughController($order, $item, 4);
    OrderedItemReceiveDate::where('store_order_item_id', $item->id)->update(['status' => 'approved', 'received_date' => now('Asia/Manila')->format('Y-m-d H:i:s')]);
    StoreOrder::whereKey($order->id)->update([
        'order_status' => OrderStatus::RECEIVED->value,
        'receiving_finalized_at' => $finalized ? now('Asia/Manila')->format('Y-m-d H:i:s') : null,
    ]);

    expect(fn () => approveMassOrderThroughController($order, $item, 9))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'already has received items');

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::RECEIVED->value)
        ->and((float) StoreOrderItem::findOrFail($item->id)->quantity_approved)->toBe(4.0)
        ->and(OrderedItemReceiveDate::where('store_order_item_id', $item->id)->count())->toBe(1);
})->with([
    'locked by Final Receive All' => [true],
    'received, list still open' => [false],
]);

it('refuses to approve a committed CPO order that nobody has received yet', function () {
    [$user, $order, $item] = createMassOrderApprovalOrder('CPO');
    $this->actingAs($user);

    approveMassOrderThroughController($order, $item, 4);

    expect(fn () => approveMassOrderThroughController($order, $item, 9))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'is already committed');

    expect((float) StoreOrderItem::findOrFail($item->id)->quantity_commited)->toBe(4.0)
        ->and(OrderedItemReceiveDate::where('store_order_item_id', $item->id)->count())->toBe(1);
});

it('refuses to approve or reject a received order of any other supplier', function () {
    [$user, $order, $item] = createMassOrderApprovalOrder('GSI-B');
    $this->actingAs($user);
    receiveMassOrderApprovalOrder($user, $order, $item);

    $controller = app(MassOrdersApprovalController::class);
    $rejectRequest = Request::create("/mass-orders-approval/reject/{$order->id}", 'POST');

    expect(fn () => approveMassOrderThroughController($order, $item, 9))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'already has received items')
        ->and(fn () => $controller->reject($rejectRequest, $order->id))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'already has received items');

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::RECEIVED->value);
});

it('refuses once a receipt is recorded, even before it is confirmed', function () {
    [$user, $order, $item] = createMassOrderApprovalOrder('GSI-B');
    $this->actingAs($user);

    approveMassOrderThroughController($order, $item, 6);
    OrderedItemReceiveDate::create([
        'store_order_item_id' => $item->id, 'received_by_user_id' => $user->id, 'quantity_received' => 6,
        'received_date' => now('Asia/Manila')->format('Y-m-d H:i:s'), 'remarks' => 'Received', 'status' => 'received',
    ]);

    expect(fn () => approveMassOrderThroughController($order, $item, 9))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'already has received items');

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::APPROVED->value);
});

it('still lets an approved order with nothing received be approved again or rejected', function () {
    [$user, $order, $item] = createMassOrderApprovalOrder('GSI-B');
    $this->actingAs($user);

    approveMassOrderThroughController($order, $item, 6);

    // A worksheet row nobody has filled in yet is not a receipt.
    OrderedItemReceiveDate::create([
        'store_order_item_id' => $item->id, 'received_by_user_id' => null, 'quantity_received' => 6,
        'received_date' => null, 'status' => 'pending',
    ]);

    approveMassOrderThroughController($order, $item, 8);

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::APPROVED->value)
        ->and((float) StoreOrderItem::findOrFail($item->id)->quantity_approved)->toBe(8.0);

    app(MassOrdersApprovalController::class)->reject(Request::create("/mass-orders-approval/reject/{$order->id}", 'POST'), $order->id);

    expect(StoreOrder::findOrFail($order->id)->order_status)->toBe(OrderStatus::REJECTED->value);

    expect(fn () => approveMassOrderThroughController($order, $item, 8))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'is already rejected');
});
