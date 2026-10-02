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

it('keeps the list open after Confirm Receive All', function () {
    [$user, $order, , $receipt] = receivingFixture();
    $this->actingAs($user);

    app(OrderReceivingController::class)->confirmReceive($order->id);

    expect(OrderedItemReceiveDate::find($receipt->id)->status)->toBe('approved')
        ->and($order->fresh()->receiving_finalized_at)->toBeNull();

    // An item found afterwards can still be added.
    addUnlisted($order, 'UNL-SUP');

    expect(StoreOrderItem::where('store_order_id', $order->id)->count())->toBe(2);
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
