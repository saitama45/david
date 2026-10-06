<?php

use App\Enums\IntercoStatus;
use App\Http\Controllers\IntercoReceivingController;
use App\Models\ImageAttachment;
use App\Models\OrderedItemReceiveDate;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Interco Receiving works as Inbound Orders does: Zero All, saving a line and Confirm Receive
 * All only record quantities, and Final Receive All alone moves the stock - out of the
 * sending store, into the receiving one - and locks the transfer.
 *
 * A transfer of 2 Pack in transit to the user's store, as the sending store's commit leaves
 * it: one row per item, pending, at the committed quantity, with no received date.
 */
function intercoReceivingFixture(bool $withImage = true): array
{
    // Fresh migrations still create a UNIQUE index on sap_masterfiles.ItemCode, but the live
    // schema has none. DDL is transactional here, so RefreshDatabase undoes it.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $user = User::factory()->create();
    $committer = User::factory()->create();

    $supplier = Supplier::create(['supplier_code' => 'ICO', 'name' => 'Interco']);
    $sending = StoreBranch::create(['branch_code' => 'ICO-S', 'name' => 'Sending Store', 'store_status' => 'active']);
    $receiving = StoreBranch::create(['branch_code' => 'ICO-R', 'name' => 'Receiving Store', 'store_status' => 'active']);
    $user->store_branches()->attach($receiving->id);

    $sap = SAPMasterfile::create([
        'ItemCode' => 'ICO-ITEM', 'ItemDescription' => 'Transfer Item', 'AltQty' => 1, 'AltUOM' => 'Pack',
        'BaseQty' => 1, 'BaseUOM' => 'Pack', 'is_active' => true,
    ]);

    $order = new StoreOrder;
    $order->forceFill([
        'encoder_id' => $committer->id,
        'supplier_id' => $supplier->id,
        'store_branch_id' => $receiving->id,
        'sending_store_branch_id' => $sending->id,
        'order_number' => 'ICO-ORDER-'.uniqid(),
        'interco_number' => 'ICO-'.uniqid(),
        'interco_status' => IntercoStatus::IN_TRANSIT->value,
        'order_date' => now('Asia/Manila')->toDateString(),
        'order_status' => 'pending',
        'variant' => 'INTERCO',
    ])->save();

    $line = StoreOrderItem::create([
        'store_order_id' => $order->id, 'item_code' => 'ICO-ITEM', 'sap_masterfile_id' => $sap->id,
        'quantity_ordered' => 2, 'quantity_approved' => 2, 'quantity_commited' => 2,
        'cost_per_quantity' => 10, 'total_cost' => 20, 'uom' => 'Pack',
    ]);

    $receipt = OrderedItemReceiveDate::create([
        'store_order_item_id' => $line->id, 'received_by_user_id' => $committer->id, 'quantity_received' => 2,
        'received_date' => null, 'status' => 'pending',
    ]);

    if ($withImage) {
        ImageAttachment::create([
            'store_order_id' => $order->id, 'file_path' => 'order_attachments/ico.png',
            'mime_type' => 'image/png', 'uploaded_by_user_id' => $user->id,
        ]);
    }

    return [
        'user' => $user, 'order' => $order->fresh(), 'sending' => $sending, 'receiving' => $receiving,
        'sap' => $sap, 'line' => $line, 'receipt' => $receipt,
    ];
}

/** The item's stock on hand at a store: interco posts a row per movement, so it is their sum. */
function intercoStock(array $f, string $store): float
{
    return (float) ProductInventoryStock::where('product_inventory_id', $f['sap']->id)
        ->where('store_branch_id', $f[$store]->id)->sum('quantity');
}

it('confirms the received quantities on Confirm Receive All without moving any stock', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    app(IntercoReceivingController::class)->confirmReceive($f['order']->interco_number);

    $receipt = OrderedItemReceiveDate::find($f['receipt']->id);
    $order = $f['order']->fresh();

    // Received by the receiving store's user now, not the committer the row was made with.
    expect($receipt->status)->toBe('received')
        ->and((float) $receipt->quantity_received)->toBe(2.0)
        ->and($receipt->received_date)->not->toBeNull()
        ->and((int) $receipt->received_by_user_id)->toBe($f['user']->id);

    // Still in transit and open: nothing left either store.
    expect($order->interco_status)->toBe(IntercoStatus::IN_TRANSIT)
        ->and($order->receiving_finalized_at)->toBeNull()
        ->and(ProductInventoryStock::count())->toBe(0)
        ->and((float) StoreOrderItem::find($f['line']->id)->quantity_received)->toBe(0.0);
});

it('moves the stock only on Final Receive All, once, and then locks the transfer', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    $controller = app(IntercoReceivingController::class);
    $number = $f['order']->interco_number;

    // A quantity corrected after confirming is the one that moves.
    $controller->confirmReceive($number);
    $controller->updateReceiveDateHistory(Request::create('/', 'POST', ['id' => $f['receipt']->id, 'quantity_received' => 1.5]));

    expect(ProductInventoryStock::count())->toBe(0);

    $controller->finalReceive($number);

    $order = $f['order']->fresh();

    expect(intercoStock($f, 'receiving'))->toBe(1.5)
        ->and(intercoStock($f, 'sending'))->toBe(-1.5)
        ->and(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe('approved')
        ->and((float) StoreOrderItem::find($f['line']->id)->quantity_received)->toBe(1.5)
        ->and($order->interco_status)->toBe(IntercoStatus::RECEIVED)
        ->and($order->receiving_finalized_at)->not->toBeNull()
        ->and((int) $order->receiving_finalized_by)->toBe($f['user']->id);

    // Finalizing again, zeroing and editing all leave the locked transfer as it is.
    $controller->finalReceive($number);
    $controller->zeroAll($number);
    $controller->updateReceiveDateHistory(Request::create('/', 'POST', ['id' => $f['receipt']->id, 'quantity_received' => 99]));

    expect(intercoStock($f, 'receiving'))->toBe(1.5)
        ->and(intercoStock($f, 'sending'))->toBe(-1.5)
        ->and((float) OrderedItemReceiveDate::find($f['receipt']->id)->quantity_received)->toBe(1.5);
});

it('posts a transfer nobody confirmed at its committed quantity on Final Receive All', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::RECEIVED);
});

it('refuses Confirm Receive All and Final Receive All without an image while a quantity is received', function () {
    $f = intercoReceivingFixture(withImage: false);
    $this->actingAs($f['user']);

    $controller = app(IntercoReceivingController::class);
    $controller->confirmReceive($f['order']->interco_number);
    $controller->finalReceive($f['order']->interco_number);

    expect(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe('pending')
        ->and($f['order']->fresh()->receiving_finalized_at)->toBeNull()
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::IN_TRANSIT)
        ->and(ProductInventoryStock::count())->toBe(0);
});

it('sets every item to 0 and Unserved on Zero All without an image, and lets that be finalized', function () {
    $f = intercoReceivingFixture(withImage: false);
    $this->actingAs($f['user']);

    $controller = app(IntercoReceivingController::class);
    $controller->zeroAll($f['order']->interco_number);

    $receipt = OrderedItemReceiveDate::find($f['receipt']->id);

    // Nothing is moved and the transfer stays open and in transit.
    expect((float) $receipt->quantity_received)->toBe(0.0)
        ->and($receipt->remarks)->toBe('Unserved')
        ->and($receipt->status)->toBe('received')
        ->and((int) $receipt->received_by_user_id)->toBe($f['user']->id)
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::IN_TRANSIT)
        ->and(ProductInventoryStock::count())->toBe(0);

    // Nothing arrived, so no image is asked for to finish it.
    $controller->finalReceive($f['order']->interco_number);

    $order = $f['order']->fresh();

    expect($order->receiving_finalized_at)->not->toBeNull()
        ->and($order->interco_status)->toBe(IntercoStatus::RECEIVED)
        ->and(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe('approved')
        ->and(intercoStock($f, 'receiving'))->toBe(0.0)
        ->and(intercoStock($f, 'sending'))->toBe(0.0);
});

it('refuses every receiving action of a store the user is not assigned to', function (string $action) {
    $f = intercoReceivingFixture();
    $this->actingAs(User::factory()->create());

    expect(fn () => app(IntercoReceivingController::class)->{$action}($f['order']->interco_number))
        ->toThrow(HttpException::class);

    expect(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe('pending')
        ->and(ProductInventoryStock::count())->toBe(0);
})->with(['zeroAll', 'confirmReceive', 'finalReceive']);

it('opens the transfer with its receiving history', function () {
    $f = intercoReceivingFixture();
    Permission::findOrCreate('view interco receiving');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view interco receiving');

    $this->actingAs($f['user'])->get(route('interco-receiving.show', $f['order']->interco_number))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('IntercoReceiving/Show')
            ->has('receiveDatesHistory', 1)
            ->where('receiveDatesHistory.0.store_order_item.item_name', 'Transfer Item')
            ->where('receivingFinalized', null));
});
