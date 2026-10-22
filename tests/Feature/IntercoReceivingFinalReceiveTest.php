<?php

use App\Enums\IntercoStatus;
use App\Http\Controllers\IntercoController;
use App\Http\Controllers\IntercoReceivingController;
use App\Http\Controllers\StoreCommitsController;
use App\Http\Services\IntercoReceivingService;
use App\Http\Services\MonthEndCountReadinessService;
use App\Http\Services\MyActionsService;
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
 * All only record quantities, and Final Receive All moves the stock - out of the sending store,
 * into the receiving one. A line received short or over is not moved: it waits for the sending
 * store to confirm it or re-send it. The transfer locks once every line is in stock.
 *
 * A transfer of 2 Pack in transit to the user's store, as the sending store's commit leaves
 * it: one row per item, pending, at the committed quantity, with no received date. 'sender' is
 * the sending store's person, who answers a line received short or over.
 */
function intercoReceivingFixture(bool $withImage = true, IntercoStatus $status = IntercoStatus::IN_TRANSIT, bool $withReceipt = true): array
{
    // Fresh migrations still create a UNIQUE index on sap_masterfiles.ItemCode, but the live
    // schema has none. DDL is transactional here, so RefreshDatabase undoes it.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    Permission::findOrCreate('receive interco requests');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $committer = User::factory()->create();
    $sender = User::factory()->create();

    $supplier = Supplier::create(['supplier_code' => 'ICO', 'name' => 'Interco']);
    $sending = StoreBranch::create(['branch_code' => 'ICO-S', 'name' => 'Sending Store', 'store_status' => 'active', 'is_active' => 1]);
    $receiving = StoreBranch::create(['branch_code' => 'ICO-R', 'name' => 'Receiving Store', 'store_status' => 'active', 'is_active' => 1]);
    $user->store_branches()->attach($receiving->id);
    $sender->store_branches()->attach($sending->id);
    $sender->givePermissionTo('receive interco requests');

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
        'interco_status' => $status->value,
        'order_date' => now('Asia/Manila')->toDateString(),
        'order_status' => 'pending',
        'variant' => 'INTERCO',
    ])->save();

    $line = StoreOrderItem::create([
        'store_order_id' => $order->id, 'item_code' => 'ICO-ITEM', 'sap_masterfile_id' => $sap->id,
        'quantity_ordered' => 2, 'quantity_approved' => 2, 'quantity_commited' => 2,
        'cost_per_quantity' => 10, 'total_cost' => 20, 'uom' => 'Pack',
    ]);

    $receipt = $withReceipt ? OrderedItemReceiveDate::create([
        'store_order_item_id' => $line->id, 'received_by_user_id' => $committer->id, 'quantity_received' => 2,
        'received_date' => null, 'status' => 'pending',
    ]) : null;

    if ($withImage) {
        ImageAttachment::create([
            'store_order_id' => $order->id, 'file_path' => 'order_attachments/ico.png',
            'mime_type' => 'image/png', 'uploaded_by_user_id' => $user->id,
        ]);
    }

    return [
        'user' => $user, 'sender' => $sender, 'order' => $order->fresh(), 'sending' => $sending, 'receiving' => $receiving,
        'sap' => $sap, 'line' => $line, 'receipt' => $receipt,
    ];
}

/** The item's stock on hand at a store: interco posts a row per movement, so it is their sum. */
function intercoStock(array $f, string $store): float
{
    return (float) ProductInventoryStock::where('product_inventory_id', $f['sap']->id)
        ->where('store_branch_id', $f[$store]->id)->sum('quantity');
}

/** The receiving store records a quantity on the fixture's line. */
function intercoRecord(array $f, float $quantity): void
{
    app(IntercoReceivingController::class)->updateReceiveDateHistory(
        Request::create('/', 'POST', ['id' => $f['receipt']->id, 'quantity_received' => $quantity])
    );
}

/** The sending store answers the lines waiting for it: 'confirm' or 'resend'. */
function intercoSenderAnswers(array $f, string $answer, array $input = []): void
{
    auth()->setUser($f['sender']);
    $controller = app(IntercoController::class);
    $request = Request::create('/', 'POST', $input);

    $answer === 'confirm'
        ? $controller->confirmReceivedQuantity($request, $f['order'])
        : $controller->resendReceivedQuantity($request, $f['order']);

    auth()->setUser($f['user']);
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

it('moves a line received as sent only on Final Receive All, once, and then locks the transfer', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    $controller = app(IntercoReceivingController::class);
    $number = $f['order']->interco_number;

    // A quantity corrected back to what was sent moves without asking the sending store.
    $controller->confirmReceive($number);
    intercoRecord($f, 1.5);
    intercoRecord($f, 2);

    expect(ProductInventoryStock::count())->toBe(0);

    $controller->finalReceive($number);

    $order = $f['order']->fresh();

    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe('approved')
        ->and((float) StoreOrderItem::find($f['line']->id)->quantity_received)->toBe(2.0)
        ->and($order->interco_status)->toBe(IntercoStatus::RECEIVED)
        ->and($order->receiving_finalized_at)->not->toBeNull()
        ->and((int) $order->receiving_finalized_by)->toBe($f['user']->id);

    // Finalizing again, zeroing and editing all leave the locked transfer as it is.
    $controller->finalReceive($number);
    $controller->zeroAll($number);
    intercoRecord($f, 99);

    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and((float) OrderedItemReceiveDate::find($f['receipt']->id)->quantity_received)->toBe(2.0);
});

it('posts a transfer nobody confirmed at its committed quantity on Final Receive All', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::RECEIVED);
});

it('shows the sending store, on the transfer list, the transfers waiting for its confirmation', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    intercoRecord($f, 1.5);
    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    // The list asks for these to show its buttons.
    foreach (['create', 'edit', 'approve', 'commit'] as $action) {
        Permission::findOrCreate("{$action} interco requests");
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $list = function (User $viewer, array $query) {
        auth()->setUser($viewer);

        return app(IntercoController::class)
            ->index(Request::create('/interco', 'GET', $query))
            ->toResponse(Request::create('/interco'))->getOriginalContent()->getData()['page']['props'];
    };

    // The sending store: counted on the For Your Confirmation card, listed by its filter, flagged as its own.
    $sender = $list($f['sender'], ['status' => IntercoReceivingService::FOR_CONFIRMATION]);
    expect($sender['statistics']['for_confirmation'])->toBe(1)
        ->and(collect($sender['orders']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$f['order']->id])
        ->and($sender['orders']['data'][0])->toMatchArray(['for_confirmation_count' => 1, 'confirmation_is_yours' => true]);

    // The receiving store sees the row flagged as waiting on the other store, and nothing to answer itself.
    $receiver = $list($f['user'], ['status' => 'all']);
    expect($receiver['statistics']['for_confirmation'])->toBe(0)
        ->and($receiver['orders']['data'][0])->toMatchArray(['for_confirmation_count' => 1, 'confirmation_is_yours' => false])
        ->and($list($f['user'], ['status' => IntercoReceivingService::FOR_CONFIRMATION])['orders']['data'])->toBe([]);

    // Once the sending store confirms, nothing waits any more.
    intercoSenderAnswers($f, 'confirm');
    expect($list($f['sender'], ['status' => 'all'])['statistics']['for_confirmation'])->toBe(0);
});

it('sends a short line to the sending store, and moves it at the received quantity once it confirms', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);
    $controller = app(IntercoReceivingController::class);

    intercoRecord($f, 1.5);
    $controller->finalReceive($f['order']->interco_number);

    $receipt = OrderedItemReceiveDate::find($f['receipt']->id);
    $order = $f['order']->fresh();

    // Nothing moved: the line waits for the sending store, and the transfer stays open.
    expect($receipt->status)->toBe(IntercoReceivingService::FOR_CONFIRMATION)
        ->and($receipt->confirmation_requested_at)->not->toBeNull()
        ->and(ProductInventoryStock::count())->toBe(0)
        ->and($order->interco_status)->toBe(IntercoStatus::IN_TRANSIT)
        ->and($order->receiving_finalized_at)->toBeNull();

    // The receiving store cannot change it while it is with the sending store.
    intercoRecord($f, 2);
    expect((float) OrderedItemReceiveDate::find($f['receipt']->id)->quantity_received)->toBe(1.5);

    intercoSenderAnswers($f, 'confirm');

    $receipt = OrderedItemReceiveDate::find($f['receipt']->id);
    $order = $f['order']->fresh();

    expect(intercoStock($f, 'receiving'))->toBe(1.5)
        ->and(intercoStock($f, 'sending'))->toBe(-1.5)
        ->and($receipt->status)->toBe('approved')
        ->and($receipt->sender_decision)->toBe('confirmed')
        ->and((int) $receipt->sender_decided_by)->toBe($f['sender']->id)
        ->and((float) StoreOrderItem::find($f['line']->id)->quantity_received)->toBe(1.5)
        ->and($order->interco_status)->toBe(IntercoStatus::RECEIVED)
        ->and($order->receiving_finalized_at)->not->toBeNull();
});

it('returns an over-received line to the receiving store on Re-Send, and moves it once it matches', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);
    $controller = app(IntercoReceivingController::class);

    intercoRecord($f, 2.5);
    $controller->finalReceive($f['order']->interco_number);

    expect(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe(IntercoReceivingService::FOR_CONFIRMATION);

    intercoSenderAnswers($f, 'resend', ['remarks' => 'We sent 2, please recount.']);

    $receipt = OrderedItemReceiveDate::find($f['receipt']->id);

    // Back with the receiving store, nothing moved, the reason kept for it.
    expect($receipt->status)->toBe('received')
        ->and($receipt->sender_decision)->toBe('resent')
        ->and($receipt->sender_remarks)->toBe('We sent 2, please recount.')
        ->and(ProductInventoryStock::count())->toBe(0)
        ->and($f['order']->fresh()->receiving_finalized_at)->toBeNull();

    // Recounted to what was sent, Final Receive All moves it without asking again.
    intercoRecord($f, 2);
    $controller->finalReceive($f['order']->interco_number);

    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::RECEIVED);
});

it('moves the lines received as sent and holds only the differing one', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);

    $sap = SAPMasterfile::create([
        'ItemCode' => 'ICO-BEANS', 'ItemDescription' => 'Beans', 'AltQty' => 1, 'AltUOM' => 'Kg',
        'BaseQty' => 1, 'BaseUOM' => 'Kg', 'is_active' => true,
    ]);
    $beans = StoreOrderItem::create([
        'store_order_id' => $f['order']->id, 'item_code' => 'ICO-BEANS', 'sap_masterfile_id' => $sap->id,
        'quantity_ordered' => 1, 'quantity_approved' => 1, 'quantity_commited' => 1,
        'cost_per_quantity' => 500, 'total_cost' => 500, 'uom' => 'Kg',
    ]);
    $beansReceipt = OrderedItemReceiveDate::create([
        'store_order_item_id' => $beans->id, 'quantity_received' => 0.7, 'received_date' => now('Asia/Manila'),
        'status' => 'received', 'received_by_user_id' => $f['user']->id,
    ]);

    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    $beansStock = fn (string $store) => (float) ProductInventoryStock::where('product_inventory_id', $sap->id)
        ->where('store_branch_id', $f[$store]->id)->sum('quantity');

    // The 2 Pack arrived as sent and moved; the 0.7 of 1 Kg waits.
    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and($beansStock('receiving'))->toBe(0.0)
        ->and(OrderedItemReceiveDate::find($beansReceipt->id)->status)->toBe(IntercoReceivingService::FOR_CONFIRMATION)
        ->and($f['order']->fresh()->receiving_finalized_at)->toBeNull();

    intercoSenderAnswers($f, 'confirm', ['store_order_item_id' => $beans->id]);

    expect(round($beansStock('receiving'), 4))->toBe(0.7)
        ->and(round($beansStock('sending'), 4))->toBe(-0.7)
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

it('sets every item to 0 and Unserved on Zero All without an image, and needs the sending store to confirm it', function () {
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

    // Nothing arrived, so no image is asked for; 0 of 2 differs, so the sending store answers.
    $controller->finalReceive($f['order']->interco_number);

    expect(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe(IntercoReceivingService::FOR_CONFIRMATION)
        ->and($f['order']->fresh()->receiving_finalized_at)->toBeNull();

    intercoSenderAnswers($f, 'confirm');

    $order = $f['order']->fresh();

    expect($order->receiving_finalized_at)->not->toBeNull()
        ->and($order->interco_status)->toBe(IntercoStatus::RECEIVED)
        ->and(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe('approved')
        ->and(intercoStock($f, 'receiving'))->toBe(0.0)
        ->and(intercoStock($f, 'sending'))->toBe(0.0);
});

it('lets only the sending store\'s people answer a line received short or over', function (string $answer) {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);
    intercoRecord($f, 1);
    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    // The receiving store's own user, even with the permission, does not answer for the sender.
    $f['user']->givePermissionTo('receive interco requests');
    $controller = app(IntercoController::class);
    $call = fn () => $answer === 'confirm'
        ? $controller->confirmReceivedQuantity(Request::create('/', 'POST'), $f['order'])
        : $controller->resendReceivedQuantity(Request::create('/', 'POST'), $f['order']);

    expect($call)->toThrow(HttpException::class);

    // Nor does a sending-store user without it.
    $this->actingAs($noPermission = User::factory()->create());
    $noPermission->store_branches()->attach($f['sending']->id);

    expect($call)->toThrow(HttpException::class);

    expect(OrderedItemReceiveDate::find($f['receipt']->id)->status)->toBe(IntercoReceivingService::FOR_CONFIRMATION)
        ->and(ProductInventoryStock::count())->toBe(0);
})->with(['confirm', 'resend']);

it('receives an approved transfer the sending store never committed', function () {
    $f = intercoReceivingFixture(status: IntercoStatus::APPROVED, withReceipt: false);
    $this->actingAs($f['user']);

    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    // Its line was opened at the quantity to send, and moved as sent.
    expect(intercoStock($f, 'receiving'))->toBe(2.0)
        ->and(intercoStock($f, 'sending'))->toBe(-2.0)
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::RECEIVED);
});

it('lets the sending store restate an approved quantity in Store Commits only until receiving starts', function () {
    $f = intercoReceivingFixture(status: IntercoStatus::APPROVED, withReceipt: false);
    Permission::findOrCreate('commit store orders');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The receiving store opened the transfer: its line exists at the approved 2.
    app(IntercoReceivingService::class)->ensureLines($f['order']);
    $receipt = OrderedItemReceiveDate::where('store_order_item_id', $f['line']->id)->sole();
    expect((float) $receipt->quantity_received)->toBe(2.0);

    // The sending store restates it to 1.5: the line the receiving store sees follows.
    $this->actingAs($f['sender']);
    app(StoreCommitsController::class)->updateCommitQuantity(Request::create('/', 'POST', ['quantity_commited' => 1.5]), $f['line']->id);

    expect((float) $receipt->fresh()->quantity_received)->toBe(1.5)
        ->and((float) $f['line']->fresh()->quantity_commited)->toBe(1.5);

    // Once the receiving store records a quantity, the sending store can no longer restate it.
    $this->actingAs($f['user']);
    app(IntercoReceivingController::class)->updateReceiveDateHistory(
        Request::create('/', 'POST', ['id' => $receipt->id, 'quantity_received' => 1.5])
    );
    expect($f['order']->fresh()->interco_status)->toBe(IntercoStatus::IN_TRANSIT);

    $this->actingAs($f['sender']);
    app(StoreCommitsController::class)->updateCommitQuantity(Request::create('/', 'POST', ['quantity_commited' => 1]), $f['line']->id);

    expect((float) $f['line']->fresh()->quantity_commited)->toBe(1.5);
});

it('refuses to receive a transfer that is not approved', function () {
    $f = intercoReceivingFixture(status: IntercoStatus::OPEN, withReceipt: false);
    $this->actingAs($f['user']);

    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);
    app(IntercoReceivingController::class)->zeroAll($f['order']->interco_number);

    expect(OrderedItemReceiveDate::count())->toBe(0)
        ->and(ProductInventoryStock::count())->toBe(0)
        ->and($f['order']->fresh()->interco_status)->toBe(IntercoStatus::OPEN);
});

it('holds the sending store\'s month end count while a line waits for its confirmation', function () {
    $f = intercoReceivingFixture();
    $this->actingAs($f['user']);
    $today = now('Asia/Manila')->toDateString();
    $keys = fn () => collect(app(MonthEndCountReadinessService::class)->blockers([$f['sending']->id], $today, $today)[$f['sending']->id] ?? [])
        ->pluck('key')->all();

    // In transit and not yet received: only the receiving store waits (no commit check).
    expect($keys())->not->toContain('interco_confirm');

    intercoRecord($f, 1);
    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    expect($keys())->toContain('interco_confirm');

    intercoSenderAnswers($f, 'confirm');

    expect($keys())->not->toContain('interco_confirm');
});

it('lists a line received short or over in My Actions for the sending store, not the receiving one', function () {
    $f = intercoReceivingFixture();
    foreach (['view interco requests', 'view interco receiving'] as $permission) {
        Permission::findOrCreate($permission);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['sender']->givePermissionTo('view interco requests');
    $f['user']->givePermissionTo(['view interco requests', 'view interco receiving', 'receive interco requests']);

    $this->actingAs($f['user']);
    $actions = fn (User $user) => collect(app(MyActionsService::class)->get($user, [])['tasks'])
        ->where('record_id', $f['order']->id)->pluck('action_key')->all();

    expect($actions($f['user']))->toBe(['interco_receive'])
        ->and($actions($f['sender']))->toBe([]);

    intercoRecord($f, 1);
    app(IntercoReceivingController::class)->finalReceive($f['order']->interco_number);

    // Everything the receiving store held went to the sending store.
    expect($actions($f['user']))->toBe([])
        ->and($actions($f['sender']))->toBe(['interco_confirm']);
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

it('opens an approved transfer with its line ready to receive at the quantity to send', function () {
    $f = intercoReceivingFixture(status: IntercoStatus::APPROVED, withReceipt: false);
    Permission::findOrCreate('view interco receiving');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view interco receiving');

    $this->actingAs($f['user'])->get(route('interco-receiving.show', $f['order']->interco_number))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('receiveDatesHistory', 1)
            ->where('receiveDatesHistory.0.status', 'pending')
            ->where('receiveDatesHistory.0.quantity_received', fn ($quantity) => (float) $quantity === 2.0));
});
