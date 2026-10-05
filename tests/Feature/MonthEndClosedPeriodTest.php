<?php

use App\Http\Controllers\DTSMassOrdersController;
use App\Http\Services\MonthEndClosedPeriodService;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndSchedule;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreTransaction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

// One HTTP request per test at most: every request disconnects the database on
// terminate(), which rolls back RefreshDatabase's transaction.

afterEach(fn () => Carbon::setTestNow());

/**
 * A store on Oct 5, 2026, and a user assigned to it who holds the given permissions.
 *
 * @return array{0: StoreBranch, 1: User}
 */
function closedPeriodStore(string $code = 'CLS', array $permissions = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));

    $store = StoreBranch::create(['branch_code' => $code, 'brand_code' => $code, 'name' => "Store {$code}", 'store_status' => 'active', 'is_active' => 1]);
    $user = User::factory()->create();
    $user->store_branches()->attach($store->id);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    $user->givePermissionTo($permissions);

    return [$store, $user];
}

/** The store's count of a month, on its MEC Scheduled Date, in the given status. */
function monthEndCount(StoreBranch $store, int $year, int $month, string $date, string $status = 'level2_approved'): void
{
    $sap = SAPMasterfile::firstOrCreate(['ItemCode' => 'CLS-ITEM'], [
        'ItemDescription' => 'Counted Item', 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'PC', 'BaseUOM' => 'PC', 'is_active' => true,
    ]);
    $schedule = MonthEndSchedule::firstOrCreate(['year' => $year, 'month' => $month], [
        'calculated_date' => $date, 'created_by' => User::factory()->create()->id,
    ]);

    MonthEndCountItem::create([
        'month_end_schedule_id' => $schedule->id,
        'branch_id' => $store->id,
        'sap_masterfile_id' => $sap->id,
        'item_code' => 'CLS-ITEM',
        'item_name' => 'Counted Item',
        'uom' => 'PC',
        'total_qty' => 5,
        'status' => $status,
        'created_by' => $schedule->created_by,
    ]);
}

function closedMessage(string $store, string $through): string
{
    return "The month end count of {$store} has its final approval, so dates on or before {$through} are closed for it. Choose a later date.";
}

it('closes a store through the MEC Scheduled Date of its final approved count only', function () {
    [$approved] = closedPeriodStore('APP');
    [$awaiting] = closedPeriodStore('AWT');
    [$returned] = closedPeriodStore('RET');
    [$uncounted] = closedPeriodStore('NON');

    monthEndCount($approved, 2026, 9, '2026-09-30');
    monthEndCount($awaiting, 2026, 9, '2026-09-30', 'level1_approved');
    monthEndCount($returned, 2026, 9, '2026-09-30', 'rejected');

    $closed = app(MonthEndClosedPeriodService::class)
        ->closedThrough([$approved->id, $awaiting->id, $returned->id, $uncounted->id]);

    expect($closed)->toBe([$approved->id => '2026-09-30']);
});

it('keeps the closed date inside the month counted and takes the latest approved count', function () {
    [$lateCount] = closedPeriodStore('LAT');
    [$earlyCount] = closedPeriodStore('ERL');

    // March's count taken on April 5 closes March, not the first days of April.
    monthEndCount($lateCount, 2026, 3, '2026-04-05');
    // A count taken before the month ends leaves the days after it open.
    monthEndCount($earlyCount, 2026, 7, '2026-07-29');
    monthEndCount($earlyCount, 2026, 6, '2026-06-30');

    $service = app(MonthEndClosedPeriodService::class);

    expect($service->closedThroughFor($lateCount->id))->toBe('2026-03-31')
        ->and($service->closedThroughFor($earlyCount->id))->toBe('2026-07-29');
});

it('holds back on a shared date picker only the dates closed for every store', function () {
    [$september] = closedPeriodStore('SEP');
    [$august] = closedPeriodStore('AUG');
    [$open] = closedPeriodStore('OPN');

    monthEndCount($september, 2026, 9, '2026-09-30');
    monthEndCount($august, 2026, 8, '2026-08-31');

    $service = app(MonthEndClosedPeriodService::class);

    expect($service->closedForAll([$september->id, $august->id]))->toBe('2026-08-31')
        ->and($service->closedForAll([$september->id, $august->id, $open->id]))->toBeNull()
        ->and($service->closedForAll([]))->toBeNull();
});

it('names the store and the last closed date, and lets later dates through', function () {
    [$store] = closedPeriodStore();
    monthEndCount($store, 2026, 9, '2026-09-30');

    $service = app(MonthEndClosedPeriodService::class);

    expect($service->problem($store->id, '2026-09-30'))->toBe(closedMessage('Store CLS', 'Sep 30, 2026'))
        ->and($service->problem($store->id, '2026-08-15T09:30'))->toBe(closedMessage('Store CLS', 'Sep 30, 2026'))
        ->and($service->problem($store->id, '2026-10-01'))->toBeNull()
        ->and($service->problem($store->id, 'not a date'))->toBeNull();
});

it('refuses a wastage dated in the closed period', function () {
    [$store, $user] = closedPeriodStore('CLS', ['create wastage record']);
    monthEndCount($store, 2026, 9, '2026-09-30');

    $this->actingAs($user)
        ->post(route('wastage.store'), ['store_branch_id' => $store->id, 'wastage_date' => '2026-09-30', 'remarks' => 'Spoiled'])
        ->assertSessionHasErrors(['wastage_date' => closedMessage('Store CLS', 'Sep 30, 2026')]);
});

it('takes a wastage dated after the closed period', function () {
    [$store, $user] = closedPeriodStore('CLS', ['create wastage record']);
    monthEndCount($store, 2026, 9, '2026-09-30');

    // The cart is still missing, so the form is refused - but not for its date.
    $this->actingAs($user)
        ->post(route('wastage.store'), ['store_branch_id' => $store->id, 'wastage_date' => '2026-10-01', 'remarks' => 'Spoiled'])
        ->assertSessionHasErrors('cartItems')
        ->assertSessionDoesntHaveErrors('wastage_date');
});

it('refuses a sale dated in the closed period', function () {
    [$store, $user] = closedPeriodStore('CLS', ['create store transactions']);
    monthEndCount($store, 2026, 9, '2026-09-30');

    $this->actingAs($user)
        ->post(route('store-transactions.store'), ['store_branch_id' => $store->id, 'order_date' => '2026-09-15'])
        ->assertSessionHasErrors(['order_date' => closedMessage('Store CLS', 'Sep 30, 2026')]);
});

/** A sale of the store on Sep 15, 2026, inside the period its September count closed. */
function closedPeriodSale(StoreBranch $store): StoreTransaction
{
    return StoreTransaction::create([
        'store_branch_id' => $store->id, 'order_date' => '2026-09-15', 'posted' => 'Y',
        'tim_number' => 'TM-1', 'receipt_number' => 'RCPT-1',
    ]);
}

it('lets a sale correction keep the closed date the sale already has', function () {
    [$store, $user] = closedPeriodStore('CLS', ['edit store transactions']);
    monthEndCount($store, 2026, 9, '2026-09-30');
    $sale = closedPeriodSale($store);

    // No items are sent, so the correction is refused - but not for its date.
    $this->actingAs($user)
        ->put(route('store-transactions.update', $sale->id), ['store_branch_id' => $store->id, 'order_date' => '2026-09-15'])
        ->assertSessionHasErrors('items')
        ->assertSessionDoesntHaveErrors('order_date');
});

it('refuses a sale correction that moves the sale to another closed date', function () {
    [$store, $user] = closedPeriodStore('CLS', ['edit store transactions']);
    monthEndCount($store, 2026, 9, '2026-09-30');
    $sale = closedPeriodSale($store);

    $this->actingAs($user)
        ->put(route('store-transactions.update', $sale->id), ['store_branch_id' => $store->id, 'order_date' => '2026-09-20'])
        ->assertSessionHasErrors(['order_date' => closedMessage('Store CLS', 'Sep 30, 2026')]);
});

it('refuses a receipt dated in the closed period', function () {
    [$store, $user] = closedPeriodStore('CLS', ['receive orders']);
    monthEndCount($store, 2026, 9, '2026-09-30');

    $order = StoreOrder::create([
        'encoder_id' => $user->id,
        'supplier_id' => Supplier::create(['supplier_code' => 'REG', 'name' => 'Regular Supplier'])->id,
        'store_branch_id' => $store->id,
        'order_number' => 'CLS-ORDER',
        'order_date' => '2026-10-02',
        'order_status' => 'committed',
        'variant' => 'mass regular',
    ]);
    $line = StoreOrderItem::create([
        'store_order_id' => $order->id, 'item_code' => 'CLS-ITEM', 'sap_masterfile_id' => SAPMasterfile::firstWhere('ItemCode', 'CLS-ITEM')->id,
        'quantity_ordered' => 2, 'quantity_approved' => 2, 'quantity_commited' => 2,
        'cost_per_quantity' => 10, 'total_cost' => 20, 'uom' => 'PC',
    ]);

    $this->actingAs($user)
        ->post(route('orders-receiving.receive', $line->id), [
            'quantity_received' => 2, 'received_date' => '2026-09-30T10:00', 'expiry_date' => '2026-12-31',
        ])
        ->assertSessionHasErrors(['received_date' => closedMessage('Store CLS', 'Sep 30, 2026')]);
});

it('leaves the closed dates out of the mass order calendar', function () {
    [$store, $user] = closedPeriodStore();
    monthEndCount($store, 2026, 9, '2026-09-30');
    $user->suppliers()->attach(Supplier::create(['supplier_code' => 'REG', 'name' => 'Regular Supplier', 'is_active' => true])->supplier_code);

    // No cutoff is set for REG, so without the count the calendar opens 60 days back.
    $dates = $this->actingAs($user)->get(route('mass-orders.available-dates', 'REG'))->assertOk()->json();

    expect(min($dates))->toBe('2026-10-01');
});

it('refuses moving a mass order into the closed period', function () {
    [$store, $user] = closedPeriodStore('CLS', ['edit mass orders']);
    monthEndCount($store, 2026, 9, '2026-09-30');

    $order = StoreOrder::create([
        'encoder_id' => $user->id,
        'supplier_id' => Supplier::create(['supplier_code' => 'REG', 'name' => 'Regular Supplier', 'is_active' => true])->id,
        'store_branch_id' => $store->id,
        'order_number' => 'CLS-ORDER',
        'order_date' => '2026-10-06',
        'order_status' => 'pending',
        'variant' => 'mass regular',
    ]);

    $this->actingAs($user)
        ->put(route('mass-orders.update', $order->order_number), [
            'supplier_id' => 'REG', 'branch_id' => $store->id, 'order_date' => '2026-09-28', 'orders' => [['id' => 1]],
        ])
        ->assertSessionHasErrors(['error' => closedMessage('Store CLS', 'Sep 30, 2026')]);
});

it('refuses a DTS quantity for a closed store and date unless the batch already holds it', function () {
    [$closed] = closedPeriodStore('CLS');
    [$open] = closedPeriodStore('OPN');
    monthEndCount($closed, 2026, 9, '2026-09-30');

    $controller = app(DTSMassOrdersController::class);
    $problem = fn (string $variant, array $orders, array $kept = []) => (new ReflectionMethod($controller, 'closedPeriodProblem'))
        ->invoke($controller, $variant, $orders, $kept);

    // Ice cream / salmon: date => store => quantity. Fruits and vegetables: item => date => store => quantity.
    expect($problem('SALMON', ['2026-09-30' => [$closed->id => 5, $open->id => 5]]))->toBe(closedMessage('Store CLS', 'Sep 30, 2026'))
        ->and($problem('FRUITS AND VEGETABLES', [7 => ['2026-09-29' => [$closed->id => 2]]]))->toBe(closedMessage('Store CLS', 'Sep 30, 2026'))
        ->and($problem('SALMON', ['2026-09-30' => [$closed->id => '', $open->id => 5], '2026-10-01' => [$closed->id => 5]]))->toBeNull()
        ->and($problem('SALMON', ['2026-09-30' => [$closed->id => 5]], ["2026-09-30|{$closed->id}"]))->toBeNull();
});
