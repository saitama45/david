<?php

use App\Enum\OrderStatus;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Services\OrderReceivingService;
use App\Models\DeliveryReceipt;
use App\Models\ImageAttachment;
use App\Models\OrderedItemReceiveDate;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An order from supplier UNL, delivered 10 days ago (past the old 3-day window), with one
 * ordered item recorded as received but not yet confirmed, a delivery receipt and an image.
 * The SAP Masterlist holds the ordered item, the supplier's other item and an item no
 * supplier lists.
 */
function receivingFixture(bool $withImage = true): array
{
    // Fresh migrations still create a UNIQUE index on sap_masterfiles.ItemCode, but the live
    // schema has none: an item has one row per AltUOM. DDL is transactional here, so
    // RefreshDatabase undoes it.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $user = User::factory()->create();

    $supplier = Supplier::create(['supplier_code' => 'UNL', 'name' => 'UNL Supplier']);
    $branch = StoreBranch::create(['branch_code' => 'UNL-BR', 'name' => 'UNL Branch', 'store_status' => 'active']);

    $sap = fn (string $code, string $name, string $uom) => SAPMasterfile::create([
        'ItemCode' => $code, 'ItemDescription' => $name, 'AltQty' => 1, 'AltUOM' => $uom,
        'BaseQty' => 1, 'BaseUOM' => $uom, 'is_active' => true,
    ]);

    $ordered = $sap('UNL-ORD', 'Ordered Item', 'Pack');
    $supplierOnly = $sap('UNL-SUP', 'Supplier Only Item', 'Pack');
    $sap('UNL-SAP', 'Masterlist Only Item', 'Kg');

    $supplierItem = fn (string $code, string $name, float $cost) => SupplierItems::create([
        'ItemCode' => $code, 'item_name' => $name, 'SupplierCode' => 'UNL', 'category' => '', 'brand' => '',
        'classification' => '', 'packaging_config' => '', 'config' => 0, 'uom' => 'Pack', 'cost' => $cost,
        'srp' => 0, 'is_active' => true, 'sort_order' => 0,
    ]);

    $supplierItem('UNL-ORD', 'Ordered Item', 10);
    $supplierItem('UNL-SUP', 'Supplier Only Item', 25);

    $order = StoreOrder::create([
        'encoder_id' => $user->id,
        'supplier_id' => $supplier->id,
        'store_branch_id' => $branch->id,
        'order_number' => 'UNL-ORDER-'.uniqid(),
        'order_date' => now('Asia/Manila')->subDays(10)->toDateString(),
        'order_status' => OrderStatus::COMMITTED->value,
        'variant' => 'mass regular',
    ]);

    $line = StoreOrderItem::create([
        'store_order_id' => $order->id, 'item_code' => 'UNL-ORD', 'sap_masterfile_id' => $ordered->id,
        'quantity_ordered' => 2, 'quantity_approved' => 2, 'quantity_commited' => 2,
        'cost_per_quantity' => 10, 'total_cost' => 20, 'uom' => 'Pack',
    ]);

    $receipt = OrderedItemReceiveDate::create([
        'store_order_item_id' => $line->id, 'received_by_user_id' => $user->id, 'quantity_received' => 2,
        'received_date' => now('Asia/Manila')->format('Y-m-d H:i:s'), 'remarks' => 'Received', 'status' => 'received',
    ]);

    DeliveryReceipt::create(['store_order_id' => $order->id, 'delivery_receipt_number' => 'DR-UNL-1', 'remarks' => 'test']);

    if ($withImage) {
        ImageAttachment::create([
            'store_order_id' => $order->id, 'file_path' => 'order_attachments/unl.png',
            'mime_type' => 'image/png', 'uploaded_by_user_id' => $user->id,
        ]);
    }

    return [$user, $order, $branch, $receipt, $ordered, $supplierOnly];
}

function addUnlisted(StoreOrder $order, string $code, string $uom = 'Pack'): StoreOrderItem
{
    return app(OrderReceivingService::class)->addUnlistedItem($order->fresh(), [
        'item_code' => $code, 'uom' => $uom, 'quantity_received' => 3, 'expiry_date' => null, 'remarks' => 'Bonus / free goods',
    ]);
}

it('adds an item from the supplier list at the supplier cost, even after the old 3-day window', function () {
    [$user, $order] = receivingFixture();
    $this->actingAs($user);

    $line = addUnlisted($order, 'UNL-SUP');

    expect($line->uom)->toBe('Pack')
        ->and((float) $line->quantity_ordered)->toBe(0.0)
        ->and((float) $line->cost_per_quantity)->toBe(25.0)
        ->and((float) $line->total_cost)->toBe(75.0);
});

it('refuses an item the supplier does not list, an item already on the order, and a unit the supplier does not list', function () {
    [$user, $order] = receivingFixture();
    $this->actingAs($user);

    expect(fn () => addUnlisted($order, 'UNL-SAP', 'Kg'))->toThrow(Exception::class, 'is not in the UNL item list')
        ->and(fn () => addUnlisted($order, 'UNL-ORD'))->toThrow(Exception::class, 'already on this order')
        ->and(fn () => addUnlisted($order, 'UNL-SUP', 'Case'))->toThrow(Exception::class, 'is not in the UNL item list');

    expect(StoreOrderItem::where('store_order_id', $order->id)->count())->toBe(1);
});

it('confirms the received quantities on Confirm Receive All without posting anything to stock', function () {
    [$user, $order, $branch, $receipt] = receivingFixture();
    $this->actingAs($user);

    // A worksheet row nobody filled in: it is received at the committed quantity it carries.
    $untouched = receivingLine($order, 'UNL-SAP', ['received_by_user_id' => null, 'quantity_received' => 3, 'received_date' => null, 'status' => 'pending']);

    app(OrderReceivingController::class)->confirmReceive($order->id);

    $untouched = OrderedItemReceiveDate::find($untouched->id);

    // Recorded, not posted: 'approved' is the posted state.
    expect(OrderedItemReceiveDate::find($receipt->id)->status)->toBe('received')
        ->and($untouched->status)->toBe('received')
        ->and((float) $untouched->quantity_received)->toBe(3.0)
        ->and($untouched->received_date)->not->toBeNull()
        ->and((int) $untouched->received_by_user_id)->toBe($user->id)
        ->and($untouched->remarks)->toBe('Received')
        ->and($order->fresh()->order_status)->toBe(OrderStatus::RECEIVED->value)
        ->and($order->fresh()->receiving_finalized_at)->toBeNull();

    // Nothing reached stock on hand, and no line carries a posted quantity.
    expect(ProductInventoryStock::where('store_branch_id', $branch->id)->exists())->toBeFalse()
        ->and((float) StoreOrderItem::find($receipt->store_order_item_id)->quantity_received)->toBe(0.0);

    // The list stays open: an item found afterwards can still be added.
    addUnlisted($order, 'UNL-SUP');

    expect(StoreOrderItem::where('store_order_id', $order->id)->count())->toBe(3);
});

it('posts to stock only on Final Receive All, and once, after Confirm Receive All', function () {
    [$user, $order, $branch, $receipt, $ordered] = receivingFixture();
    $this->actingAs($user);

    $controller = app(OrderReceivingController::class);
    $stock = fn () => (float) ProductInventoryStock::where('product_inventory_id', $ordered->id)
        ->where('store_branch_id', $branch->id)->value('quantity');

    // Confirming, even twice, leaves stock on hand alone.
    $controller->confirmReceive($order->id);
    $controller->confirmReceive($order->id);

    expect($stock())->toBe(0.0);

    // A quantity corrected after confirming is the one that reaches stock.
    $controller->updateReceiveDateHistory(Request::create('/', 'POST', [
        'id' => $receipt->id, 'quantity_received' => 1.5, 'remarks' => 'Short delivery',
    ]));

    expect($stock())->toBe(0.0);

    $controller->finalReceive($order->id);

    expect($stock())->toBe(1.5)
        ->and(OrderedItemReceiveDate::find($receipt->id)->status)->toBe('approved')
        ->and((float) StoreOrderItem::find($receipt->store_order_item_id)->quantity_received)->toBe(1.5)
        ->and($order->fresh()->receiving_finalized_at)->not->toBeNull();
});

it('lists under For Final Receive All only an open delivery with received quantities not in stock yet', function () {
    [$user, $order] = receivingFixture();
    $this->actingAs($user);

    $recorded = ['received_by_user_id' => $user->id, 'quantity_received' => 3, 'received_date' => now('Asia/Manila')->format('Y-m-d H:i:s'), 'remarks' => 'Received'];

    // Another delivery of the same store and supplier, with one row per given state.
    $delivery = function (string $orderStatus, array $rowStatuses, bool $finalized = false) use ($order, $recorded) {
        $other = StoreOrder::create([
            'encoder_id' => $order->encoder_id, 'supplier_id' => $order->supplier_id, 'store_branch_id' => $order->store_branch_id,
            'order_number' => 'UNL-ORDER-'.uniqid(), 'order_date' => $order->order_date, 'order_status' => $orderStatus, 'variant' => 'mass regular',
        ]);

        foreach ($rowStatuses as $index => $status) {
            receivingLine($other, ['UNL-ORD', 'UNL-SUP'][$index], $status === 'pending'
                ? ['received_by_user_id' => null, 'quantity_received' => 3, 'received_date' => null, 'status' => 'pending']
                : $recorded + ['status' => $status]);
        }

        if ($finalized) {
            StoreOrder::whereKey($other->id)->update(['receiving_finalized_at' => now('Asia/Manila')->format('Y-m-d H:i:s')]);
        }

        return (int) $other->id;
    };

    // Nobody has received anything yet; everything is already in stock (confirmed before
    // this rule); an item added after that; and a delivery already locked.
    $delivery(OrderStatus::COMMITTED->value, ['pending']);
    $delivery(OrderStatus::RECEIVED->value, ['approved']);
    $addedLater = $delivery(OrderStatus::RECEIVED->value, ['approved', 'received']);
    $delivery(OrderStatus::RECEIVED->value, ['received'], finalized: true);

    $service = app(OrderReceivingService::class);
    $listed = fn () => $service->applyStatusFilter(StoreOrder::query(), OrderReceivingService::FOR_FINAL_RECEIVE)
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

    // The fixture's own delivery has a recorded quantity and is still open.
    expect($listed())->toBe([(int) $order->id, $addedLater])
        ->and($service->getCounts(StoreOrder::query())[OrderReceivingService::FOR_FINAL_RECEIVE])->toBe(2);

    // Confirm Receive All does not take it off the list; Final Receive All does.
    $controller = app(OrderReceivingController::class);
    $controller->confirmReceive($order->id);

    expect($listed())->toBe([(int) $order->id, $addedLater]);

    $controller->finalReceive($order->id);

    expect($listed())->toBe([$addedLater]);
});

it('posts every unconfirmed receipt on Final Receive All and then locks the item list', function () {
    [$user, $order, $branch, $receipt, $ordered] = receivingFixture();
    $this->actingAs($user);

    $controller = app(OrderReceivingController::class);
    $controller->finalReceive($order->id);

    $order->refresh();

    expect($order->receiving_finalized_at)->not->toBeNull()
        ->and((int) $order->receiving_finalized_by)->toBe($user->id)
        ->and(OrderedItemReceiveDate::find($receipt->id)->status)->toBe('approved')
        ->and((float) ProductInventoryStock::where('product_inventory_id', $ordered->id)
            ->where('store_branch_id', $branch->id)->value('quantity'))->toBe(2.0);

    // Nothing can be added.
    expect(fn () => addUnlisted($order, 'UNL-SUP'))->toThrow(Exception::class, 'finalized with Final Receive All')
        ->and(app(OrderReceivingService::class)->receivingLockedProblem($order))->toContain('by '.$user->full_name);

    // Nothing can be edited or removed: the receipt keeps its quantity and still exists.
    $controller->updateReceiveDateHistory(Request::create('/', 'POST', [
        'id' => $receipt->id, 'quantity_received' => 99, 'remarks' => 'changed',
    ]));
    $controller->deleteReceiveDateHistory($receipt->id);

    $kept = OrderedItemReceiveDate::find($receipt->id);

    expect($kept)->not->toBeNull()
        ->and((float) $kept->quantity_received)->toBe(2.0)
        ->and($kept->status)->toBe('approved');

    // Finalizing twice posts nothing again.
    $controller->finalReceive($order->id);

    expect((float) ProductInventoryStock::where('product_inventory_id', $ordered->id)
        ->where('store_branch_id', $branch->id)->value('quantity'))->toBe(2.0)
        ->and(StoreOrderItem::where('store_order_id', $order->id)->count())->toBe(1);
});

it('refuses Final Receive All without a delivery receipt or image', function () {
    [$user, $order, , $receipt] = receivingFixture(withImage: false);
    $this->actingAs($user);

    app(OrderReceivingController::class)->finalReceive($order->id);

    expect($order->fresh()->receiving_finalized_at)->toBeNull()
        ->and(OrderedItemReceiveDate::find($receipt->id)->status)->toBe('received');
});

it('refuses every receiving action before the Delivery Date and allows them from that day on', function () {
    [$user, $order, $branch, $receipt, $ordered] = receivingFixture();
    $this->actingAs($user);

    $service = app(OrderReceivingService::class);
    $controller = app(OrderReceivingController::class);

    // Due tomorrow, Manila time.
    $tomorrow = now('Asia/Manila')->addDay();
    StoreOrder::whereKey($order->id)->update(['order_date' => $tomorrow->toDateString()]);
    $order->refresh();

    expect($service->receivingOpensOn($order)?->toDateString())->toBe($tomorrow->toDateString())
        ->and($service->receivingNotDueProblem($order))->toContain('due on '.$tomorrow->format('M j, Y'));

    // Nothing may be edited, zeroed, confirmed, finalized or added.
    $controller->updateReceiveDateHistory(Request::create('/', 'POST', [
        'id' => $receipt->id, 'quantity_received' => 1, 'remarks' => 'changed',
    ]));
    $controller->zeroAll($order->id);
    $controller->confirmReceive($order->id);
    $controller->finalReceive($order->id);

    expect(fn () => addUnlisted($order, 'UNL-SUP'))->toThrow(Exception::class, 'on its Delivery Date or later');

    $kept = OrderedItemReceiveDate::find($receipt->id);

    expect((float) $kept->quantity_received)->toBe(2.0)
        ->and($kept->remarks)->toBe('Received')
        ->and($kept->status)->toBe('received')
        ->and($order->fresh()->order_status)->toBe(OrderStatus::COMMITTED->value)
        ->and($order->fresh()->receiving_finalized_at)->toBeNull()
        ->and(StoreOrderItem::where('store_order_id', $order->id)->count())->toBe(1)
        ->and(ProductInventoryStock::where('store_branch_id', $branch->id)->exists())->toBeFalse();

    // On the Delivery Date itself receiving is open.
    StoreOrder::whereKey($order->id)->update(['order_date' => now('Asia/Manila')->toDateString()]);
    $order->refresh();

    expect($service->receivingOpensOn($order))->toBeNull()
        ->and($service->receivingNotDueProblem($order))->toBeNull();

    $controller->finalReceive($order->id);

    expect($order->fresh()->receiving_finalized_at)->not->toBeNull()
        ->and((float) ProductInventoryStock::where('product_inventory_id', $ordered->id)
            ->where('store_branch_id', $branch->id)->value('quantity'))->toBe(2.0);
});

/** A second line of the fixture order with one receiving row in the given state. */
function receivingLine(StoreOrder $order, string $code, array $receipt): OrderedItemReceiveDate
{
    $line = StoreOrderItem::create([
        'store_order_id' => $order->id, 'item_code' => $code,
        'sap_masterfile_id' => SAPMasterfile::where('ItemCode', $code)->value('id'),
        'quantity_ordered' => 3, 'quantity_approved' => 3, 'quantity_commited' => 3,
        'cost_per_quantity' => 25, 'total_cost' => 75, 'uom' => 'Pack',
    ]);

    return OrderedItemReceiveDate::create(['store_order_item_id' => $line->id] + $receipt);
}

it('sets every unconfirmed receipt to 0 and Unserved on Zero All, without a delivery receipt or image', function () {
    [$user, $order, $branch, $recorded, $ordered] = receivingFixture(withImage: false);
    $this->actingAs($user);

    // A worksheet row nobody filled in, and a receipt that is already posted to stock.
    $untouched = receivingLine($order, 'UNL-SUP', ['received_by_user_id' => null, 'quantity_received' => 3, 'received_date' => null, 'status' => 'pending']);
    $posted = receivingLine($order, 'UNL-SAP', [
        'received_by_user_id' => $user->id, 'approval_action_by' => $user->id, 'quantity_received' => 1,
        'received_date' => now('Asia/Manila')->format('Y-m-d H:i:s'), 'remarks' => 'Received', 'status' => 'approved',
    ]);

    $service = app(OrderReceivingService::class);
    expect($service->deliveryEvidenceProblem($order))->toContain('an image attachment');

    $controller = app(OrderReceivingController::class);
    $controller->zeroAll($order->id);

    foreach ([$recorded, $untouched] as $row) {
        $row = OrderedItemReceiveDate::find($row->id);

        expect((float) $row->quantity_received)->toBe(0.0)
            ->and($row->remarks)->toBe('Unserved')
            ->and($row->status)->toBe('received')
            ->and($row->received_date)->not->toBeNull()
            ->and((int) $row->received_by_user_id)->toBe($user->id);
    }

    $posted = OrderedItemReceiveDate::find($posted->id);

    // Nothing is posted and the list stays open; the posted receipt keeps its quantity.
    expect((float) $posted->quantity_received)->toBe(1.0)
        ->and($posted->status)->toBe('approved')
        ->and($posted->remarks)->toBe('Received')
        ->and($order->fresh()->order_status)->toBe(OrderStatus::COMMITTED->value)
        ->and($order->fresh()->receiving_finalized_at)->toBeNull()
        ->and(ProductInventoryStock::where('product_inventory_id', $ordered->id)->where('store_branch_id', $branch->id)->exists())->toBeFalse();

    // One receipt above zero is on the order, so finalizing still needs the image.
    $controller->finalReceive($order->id);

    expect($order->fresh()->receiving_finalized_at)->toBeNull()
        ->and($service->postingEvidenceProblem($order))->toContain('an image attachment');
});

it('lets an order with nothing received be finalized without a delivery receipt or image', function () {
    [$user, $order, $branch, $receipt, $ordered] = receivingFixture(withImage: false);
    $this->actingAs($user);

    $controller = app(OrderReceivingController::class);
    $controller->zeroAll($order->id);

    expect(app(OrderReceivingService::class)->postingEvidenceProblem($order))->toBeNull();

    $controller->finalReceive($order->id);

    $order->refresh();
    $receipt = OrderedItemReceiveDate::find($receipt->id);

    expect($order->receiving_finalized_at)->not->toBeNull()
        ->and($order->order_status)->toBe(OrderStatus::RECEIVED->value)
        ->and($receipt->status)->toBe('approved')
        ->and((float) $receipt->quantity_received)->toBe(0.0)
        ->and($receipt->remarks)->toBe('Unserved')
        ->and((float) StoreOrderItem::find($receipt->store_order_item_id)->quantity_received)->toBe(0.0)
        ->and((float) ProductInventoryStock::where('product_inventory_id', $ordered->id)
            ->where('store_branch_id', $branch->id)->value('quantity'))->toBe(0.0);
});

it('refuses Zero All once the delivery is finalized', function () {
    [$user, $order, , $receipt] = receivingFixture();
    $this->actingAs($user);

    $controller = app(OrderReceivingController::class);
    $controller->finalReceive($order->id);

    // A row that should not exist on a locked delivery: Zero All must not touch it either.
    $stray = receivingLine($order, 'UNL-SUP', ['received_by_user_id' => null, 'quantity_received' => 3, 'received_date' => null, 'status' => 'pending']);

    $controller->zeroAll($order->id);

    expect((float) OrderedItemReceiveDate::find($receipt->id)->quantity_received)->toBe(2.0)
        ->and((float) OrderedItemReceiveDate::find($stray->id)->quantity_received)->toBe(3.0)
        ->and(OrderedItemReceiveDate::find($stray->id)->status)->toBe('pending');
});
