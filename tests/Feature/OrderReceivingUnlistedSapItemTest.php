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
use Illuminate\Support\Facades\DB;

/**
 * An order from supplier UNL with one ordered item, a delivery receipt and an image, and a
 * SAP Masterlist holding: the ordered item, the supplier's other item, an item no supplier
 * lists (Kg, with 1000 Gm = 1 Kg) and an inactive item.
 */
function unlistedSapFixture(): array
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

    $sap = fn (string $code, string $name, float $altQty, string $alt, float $baseQty, string $base, bool $active = true) => SAPMasterfile::create([
        'ItemCode' => $code, 'ItemDescription' => $name, 'AltQty' => $altQty, 'AltUOM' => $alt,
        'BaseQty' => $baseQty, 'BaseUOM' => $base, 'is_active' => $active,
    ]);

    $ordered = $sap('UNL-ORD', 'Ordered Item', 1, 'Pack', 1, 'Pack');
    $sap('UNL-SUP', 'Supplier Only Item', 1, 'Pack', 1, 'Pack');
    $sapKg = $sap('UNL-SAP', 'Masterlist Only Item', 1, 'Kg', 1, 'Kg');
    $sap('UNL-SAP', 'Masterlist Only Item', 1000, 'Gm', 1, 'Kg');
    $sap('UNL-OFF', 'Inactive Item', 1, 'Pack', 1, 'Pack', false);

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
        'order_date' => now('Asia/Manila')->toDateString(),
        'order_status' => OrderStatus::COMMITTED->value,
        'variant' => 'mass regular',
    ]);

    StoreOrderItem::create([
        'store_order_id' => $order->id, 'item_code' => 'UNL-ORD', 'sap_masterfile_id' => $ordered->id,
        'quantity_ordered' => 2, 'quantity_approved' => 2, 'quantity_commited' => 2,
        'cost_per_quantity' => 10, 'total_cost' => 20, 'uom' => 'Pack',
    ]);

    DeliveryReceipt::create(['store_order_id' => $order->id, 'delivery_receipt_number' => 'DR-UNL-1', 'remarks' => 'test']);
    ImageAttachment::create([
        'store_order_id' => $order->id, 'file_path' => 'order_attachments/unl.png',
        'mime_type' => 'image/png', 'uploaded_by_user_id' => $user->id,
    ]);

    return [$user, $order, $branch, $sapKg];
}

it('offers active SAP Masterlist items that are not on the order, one row per unit', function () {
    [, $order] = unlistedSapFixture();

    $result = app(OrderReceivingService::class)->unlistedSapItems($order);
    $rows = collect($result['items'])->map(fn ($item) => $item['item_code'].'|'.$item['uom'])->all();

    expect($rows)->toContain('UNL-SUP|Pack', 'UNL-SAP|Kg', 'UNL-SAP|Gm')
        ->and($rows)->not->toContain('UNL-ORD|Pack')
        ->and($rows)->not->toContain('UNL-OFF|Pack')
        ->and($result['available'])->toBe(2)
        ->and($result['more'])->toBeFalse();

    // The page asks for a cost only where the order's supplier has no price for the row.
    $listed = collect($result['items'])->mapWithKeys(fn ($item) => [$item['item_code'].'|'.$item['uom'] => $item['supplier_listed']]);

    expect($listed['UNL-SUP|Pack'])->toBeTrue()
        ->and($listed['UNL-SAP|Kg'])->toBeFalse()
        ->and($listed['UNL-SAP|Gm'])->toBeFalse();

    $searched = app(OrderReceivingService::class)->unlistedSapItems($order, 'masterlist only');

    expect(collect($searched['items'])->pluck('item_code')->unique()->values()->all())->toBe(['UNL-SAP']);
});

it('receives an item that is only in the SAP Masterlist, in the unit picked, and posts it to stock', function () {
    [$user, $order, $branch, $sapKg] = unlistedSapFixture();
    $this->actingAs($user);

    $line = app(OrderReceivingService::class)->addUnlistedItem($order, [
        'item_code' => 'UNL-SAP',
        'uom' => 'Gm',
        'quantity_received' => 500,
        'expiry_date' => null,
        'remarks' => 'Delivered but not ordered',
    ]);

    $receipt = OrderedItemReceiveDate::where('store_order_item_id', $line->id)->first();

    expect($line->uom)->toBe('Gm')
        ->and((float) $line->quantity_ordered)->toBe(0.0)
        ->and((float) $line->quantity_commited)->toBe(500.0)
        // No price is on file for this item and none was typed, so it comes in at zero.
        ->and((float) $line->cost_per_quantity)->toBe(0.0)
        ->and((float) $line->total_cost)->toBe(0.0)
        ->and($receipt->status)->toBe('received')
        ->and((float) $receipt->quantity_received)->toBe(500.0);

    app(OrderReceivingController::class)->confirmReceive($order->id);

    $stock = ProductInventoryStock::where('product_inventory_id', $sapKg->id)
        ->where('store_branch_id', $branch->id)
        ->first();

    // 500 Gm lands on the item's Kg stock row as 0.5 Kg.
    expect($stock)->not->toBeNull()
        ->and((float) $stock->quantity)->toBe(0.5)
        ->and(OrderedItemReceiveDate::find($receipt->id)->status)->toBe('approved');
});

it('receives a SAP Masterlist item at the cost the receiver typed', function () {
    [$user, $order] = unlistedSapFixture();
    $this->actingAs($user);

    $line = app(OrderReceivingService::class)->addUnlistedItem($order, [
        'item_code' => 'UNL-SAP',
        'uom' => 'Kg',
        'cost' => 12.5,
        'quantity_received' => 4,
        'expiry_date' => null,
        'remarks' => 'Delivered but not ordered',
    ]);

    expect((float) $line->cost_per_quantity)->toBe(12.5)
        ->and((float) $line->total_cost)->toBe(50.0);
});

it('still takes a supplier list item at the supplier cost, whatever cost is sent', function () {
    [$user, $order] = unlistedSapFixture();
    $this->actingAs($user);

    $line = app(OrderReceivingService::class)->addUnlistedItem($order, [
        'item_code' => 'UNL-SUP',
        'uom' => 'Pack',
        'cost' => 999,
        'quantity_received' => 3,
        'expiry_date' => null,
        'remarks' => 'Bonus / free goods',
    ]);

    expect($line->uom)->toBe('Pack')
        ->and((float) $line->cost_per_quantity)->toBe(25.0)
        ->and((float) $line->total_cost)->toBe(75.0);
});

it('refuses an inactive SAP item, an item already on the order, and a unit the item does not have', function () {
    [$user, $order] = unlistedSapFixture();
    $this->actingAs($user);

    $add = fn (string $code, string $uom) => app(OrderReceivingService::class)->addUnlistedItem($order, [
        'item_code' => $code, 'uom' => $uom, 'quantity_received' => 1, 'expiry_date' => null, 'remarks' => 'test',
    ]);

    expect(fn () => $add('UNL-OFF', 'Pack'))->toThrow(Exception::class, 'not an active item in the SAP Masterlist')
        ->and(fn () => $add('UNL-ORD', 'Pack'))->toThrow(Exception::class, 'already on this order')
        ->and(fn () => $add('UNL-SAP', 'Case'))->toThrow(Exception::class, 'not an active item in the SAP Masterlist');

    expect(StoreOrderItem::where('store_order_id', $order->id)->count())->toBe(1);
});
