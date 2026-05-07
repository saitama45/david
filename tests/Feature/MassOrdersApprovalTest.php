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
