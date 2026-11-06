<?php

use App\Enum\OrderStatus;
use App\Http\Controllers\CpoOrdersController;
use App\Http\Controllers\MassOrdersApprovalController;
use App\Http\Controllers\MassOrdersController;
use App\Models\CpoVendor;
use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A user assigned to two stores set up for CPO, the CPO template and one GSI-B item it
 * consolidates, and a vendor.
 *
 * @return array{0: User, 1: StoreBranch, 2: StoreBranch, 3: CpoVendor, 4: Supplier}
 */
function cpoFixture(): array
{
    $user = User::factory()->create();
    $cpo = Supplier::create(['supplier_code' => 'CPO', 'name' => 'CPO', 'is_active' => true, 'is_forapproval_massorders' => false]);
    Supplier::create(['supplier_code' => 'GSI-B', 'name' => 'GSI Bakery', 'is_active' => true]);

    SupplierItems::create([
        'ItemCode' => 'BREAD-01', 'item_name' => 'Pandesal', 'SupplierCode' => 'GSI-B', 'uom' => 'PCK',
        'category' => '', 'brand' => '', 'classification' => '', 'packaging_config' => '', 'config' => 0,
        'cost' => 10, 'is_active' => true,
    ]);

    $monday = DeliverySchedule::firstOrCreate(['day' => 'MONDAY']);
    $stores = collect(['CPO-A', 'CPO-B'])->map(function ($code) use ($user, $monday) {
        $store = StoreBranch::create(['branch_code' => $code, 'brand_code' => $code, 'name' => "Store {$code}", 'store_status' => 'active', 'is_active' => true]);
        $store->delivery_schedules()->attach($monday->id, ['variant' => 'CPO']);
        $user->store_branches()->attach($store->id);

        return $store;
    });

    $vendor = CpoVendor::create(['name' => 'Corner Market', 'tin' => '123-456', 'address' => 'Quezon City']);
    $user->suppliers()->attach('CPO'); // placing a CPO order needs the template assigned

    return [$user, $stores[0], $stores[1], $vendor, $cpo];
}

/** An .xlsx laid out like the CPO Orders template. */
function cpoOrderFile(array $rows, array $headers = ['Ordering Template', 'Category', 'Classification', 'Item Code', 'Item Name', 'Packaging Config', 'Unit', 'Cost', 'CPO-A', 'CPO-B']): UploadedFile
{
    $sheet = (new Spreadsheet)->getActiveSheet();
    $sheet->fromArray(array_merge([$headers], $rows));
    $path = tempnam(sys_get_temp_dir(), 'cpo').'.xlsx';
    (new Xlsx($sheet->getParent()))->save($path);

    return new UploadedFile($path, 'cpo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function uploadCpoOrder(array $params, array $files)
{
    $request = Request::create('/cpo-orders/upload', 'POST', $params, [], $files);

    return app(CpoOrdersController::class)->upload($request);
}

beforeEach(function () {
    Storage::fake('local');
});

it('creates one pending CPO order per store with the typed cost and the vendor, and no invoice yet', function () {
    [$user, $storeA, $storeB, $vendor] = cpoFixture();
    $this->actingAs($user);

    uploadCpoOrder(
        ['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id],
        ['mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', 'Pandesal', '', 'PCK', 12.5, 2, 3]])]
    );

    expect(session('success'))->toBeTrue()
        ->and(session('created_count'))->toBe(2);

    $orders = StoreOrder::with('store_order_items', 'cpoAttachments')->orderBy('store_branch_id')->get();

    expect($orders)->toHaveCount(2);
    foreach ($orders as $order) {
        expect($order->order_status)->toBe(OrderStatus::PENDING->value)
            ->and($order->variant)->toBe('mass regular')
            ->and((int) $order->cpo_vendor_id)->toBe($vendor->id)
            ->and($order->cpo_vendor_name)->toBe('Corner Market')
            ->and($order->cpo_vendor_tin)->toBe('123-456')
            ->and($order->cpo_vendor_address)->toBe('Quezon City')
            // The store uploads the invoice / receipt in Inbound Orders when it receives the order.
            ->and($order->cpoAttachments)->toHaveCount(0)
            ->and((float) $order->store_order_items->first()->cost_per_quantity)->toBe(12.5);
    }

    $lineA = $orders->firstWhere('store_branch_id', $storeA->id)->store_order_items->first();
    expect((float) $lineA->quantity_ordered)->toBe(2.0)
        ->and((float) $lineA->total_cost)->toBe(25.0)
        ->and($lineA->uom)->toBe('PCK');
});

it('refuses the whole upload when a line with a quantity has no cost or an unknown item', function () {
    [$user, , , $vendor] = cpoFixture();
    $this->actingAs($user);

    $upload = fn () => uploadCpoOrder(
        ['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id],
        [
            'mass_order_file' => cpoOrderFile([
                ['CPO', '', '', 'BREAD-01', 'Pandesal', '', 'PCK', '', 2, ''],
                ['CPO', '', '', 'NOPE-99', 'Unknown', '', 'PCK', 5, 1, ''],
                ['CPO', '', '', 'BREAD-01', 'Pandesal', '', '', 5, '', ''], // no quantity: not checked
            ]),
        ]
    );

    try {
        $upload();
        $this->fail('The upload should have been refused.');
    } catch (ValidationException $e) {
        $problems = collect($e->errors())->filter(fn ($m, $key) => str_starts_with($key, 'rows.'))->flatten();
        expect($problems)->toHaveCount(2)
            ->and($problems[0])->toContain('Row 2 (BREAD-01): the Cost is required')
            ->and($problems[1])->toContain('Row 3 (NOPE-99): the item is not in the CPO list');
    }

    expect(StoreOrder::count())->toBe(0);
});

it('places an order with no vendor yet, once per store and date, and needs no invoice or receipt', function () {
    [$user, , , $vendor] = cpoFixture();
    $this->actingAs($user);
    $file = fn () => ['mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', '', '', 'PCK', 5, 1, '']])];

    uploadCpoOrder(['order_date' => '2026-10-19'], $file());

    $order = StoreOrder::sole();
    expect(session('created_count'))->toBe(1)
        ->and($order->order_status)->toBe(OrderStatus::PENDING->value)
        ->and($order->cpo_vendor_id)->toBeNull()
        ->and($order->cpo_vendor_name)->toBeNull()
        ->and($order->cpo_vendor_tin)->toBeNull()
        ->and($order->cpo_vendor_address)->toBeNull();

    // The same file uploaded twice is still caught.
    uploadCpoOrder(['order_date' => '2026-10-19'], $file());
    expect(StoreOrder::count())->toBe(1)
        ->and(session('skipped_stores')[0]['reason'])->toContain('with no vendor yet');

    // A named vendor is a purchase of its own.
    uploadCpoOrder(['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id], $file());
    expect(StoreOrder::count())->toBe(2);

    // A vendor that is not on the list is refused, not taken as blank.
    expect(fn () => uploadCpoOrder(['order_date' => '2026-10-20', 'cpo_vendor_id' => 999999], $file()))
        ->toThrow(ValidationException::class);
});

it('skips a store that already has an open order from the vendor that day, but not after a rejection', function () {
    [$user, $storeA, , $vendor] = cpoFixture();
    $this->actingAs($user);

    $upload = fn () => uploadCpoOrder(
        ['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id],
        ['mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', '', '', 'PCK', 5, 1, '']])]
    );

    $upload();
    $upload();

    expect(StoreOrder::count())->toBe(1)
        ->and(session('skipped_stores')[0]['brand_code'])->toBe('CPO-A');

    StoreOrder::query()->update(['order_status' => OrderStatus::REJECTED->value]);
    $upload();

    expect(StoreOrder::where('order_status', OrderStatus::PENDING->value)->count())->toBe(1);
});

it('adds and edits a vendor, with a required address and an optional TIN, and offers no delete', function () {
    [$user, , , $vendor] = cpoFixture();
    $this->actingAs($user);
    $controller = app(CpoOrdersController::class);

    $created = $controller->storeVendor(Request::create('/cpo-orders/vendors', 'POST', ['name' => 'Palengke Stall 4', 'address' => 'Market Rd']))->getData(true);
    expect($created['tin'])->toBeNull()
        ->and((int) CpoVendor::find($created['id'])->created_by)->toBe($user->id);

    expect(fn () => $controller->storeVendor(Request::create('/cpo-orders/vendors', 'POST', ['name' => 'No Address'])))
        ->toThrow(ValidationException::class)
        ->and(fn () => $controller->storeVendor(Request::create('/cpo-orders/vendors', 'POST', ['name' => 'Corner Market', 'address' => 'Elsewhere'])))
        ->toThrow(ValidationException::class);

    $updated = $controller->updateVendor(Request::create("/cpo-orders/vendors/{$vendor->id}", 'PUT', ['name' => 'Corner Market', 'tin' => '999', 'address' => 'Makati']), $vendor)->getData(true);
    expect($updated['address'])->toBe('Makati')->and($updated['tin'])->toBe('999');

    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'cpo-orders.vendors.'));
    expect($routes->sort()->values()->all())->toBe(['cpo-orders.vendors.search', 'cpo-orders.vendors.store', 'cpo-orders.vendors.update']);
});

it('searches vendors from three characters, and finds a shorter name only when typed in full', function () {
    [$user] = cpoFixture();
    $this->actingAs($user);
    CpoVendor::create(['name' => 'JB', 'address' => 'Pasig']);
    $controller = app(CpoOrdersController::class);
    $search = fn ($q) => collect($controller->vendors(Request::create('/cpo-orders/vendors', 'GET', ['q' => $q]))->getData(true))->pluck('name')->all();

    expect($search('Co'))->toBe([])
        ->and($search('JB'))->toBe(['JB'])
        ->and($search('orn'))->toBe(['Corner Market'])
        ->and($search('123'))->toBe(['Corner Market']);
});

it('no longer takes CPO through Mass Orders', function () {
    [$user, , , , $cpo] = cpoFixture();
    $this->actingAs($user);

    $request = Request::create('/mass-orders/upload', 'POST', ['supplier_code' => 'CPO', 'order_date' => '2026-10-19'], [], [
        'mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', '', '', 'PCK', 5, 1, '']]),
    ]);
    app(MassOrdersController::class)->uploadMassOrder($request);

    expect(session('success'))->toBeFalse()
        ->and(session('message'))->toBe('CPO is ordered through CPO Orders now.')
        ->and(StoreOrder::count())->toBe(0);
});

it('lists CPO orders in Mass Orders Approval even when the CPO approval switch is off', function () {
    [$user, $storeA, , $vendor, $cpo] = cpoFixture();
    $this->actingAs($user);

    $order = StoreOrder::create([
        'encoder_id' => $user->id, 'supplier_id' => $cpo->id, 'store_branch_id' => $storeA->id,
        'order_number' => 'CPO-A-00001', 'order_date' => '2026-10-19', 'order_status' => OrderStatus::PENDING->value,
        'variant' => 'mass regular', 'cpo_vendor_id' => $vendor->id, 'cpo_vendor_name' => $vendor->name,
    ]);

    $props = app(MassOrdersApprovalController::class)->index(Request::create('/mass-orders-approval'))
        ->toResponse(Request::create('/mass-orders-approval'))->getOriginalContent()->getData()['page']['props'];

    expect(collect($props['orders']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$order->id])
        ->and($props['counts']['pending'])->toBe(1);
});

it('lets a user without the CPO template assigned see CPO Orders but not place an order or add a vendor', function () {
    [$user, , , $vendor] = cpoFixture();
    $user->suppliers()->detach('CPO');
    $this->actingAs($user);
    $controller = app(CpoOrdersController::class);

    $props = $controller->index(Request::create('/cpo-orders'))->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props'];
    expect($props['cpoAssigned'])->toBeFalse();

    expect(fn () => uploadCpoOrder(['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id], [
        'mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', '', '', 'PCK', 5, 1, '']]),
    ]))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => $controller->storeVendor(Request::create('/cpo-orders/vendors', 'POST', ['name' => 'X', 'address' => 'Y'])))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(StoreOrder::count())->toBe(0);
});

/** A CPO order of the store: 2 PCK of BREAD-01 at 10. */
function placedCpoOrder(User $user, StoreBranch $store, Supplier $cpo, ?CpoVendor $vendor = null, string $status = 'pending', string $date = '2026-10-19'): StoreOrder
{
    $order = StoreOrder::create([
        'encoder_id' => $user->id, 'supplier_id' => $cpo->id, 'store_branch_id' => $store->id,
        'order_number' => $store->branch_code.'-'.str_pad((string) (StoreOrder::count() + 1), 5, '0', STR_PAD_LEFT),
        'order_date' => $date, 'order_status' => $status, 'variant' => 'mass regular',
        'cpo_vendor_id' => $vendor?->id, 'cpo_vendor_name' => $vendor?->name,
        'cpo_vendor_tin' => $vendor?->tin, 'cpo_vendor_address' => $vendor?->address,
    ]);
    $order->store_order_items()->create([
        'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity_ordered' => 2, 'quantity_approved' => 2,
        'quantity_commited' => 2, 'cost_per_quantity' => 10, 'total_cost' => 20,
    ]);

    return $order->refresh();
}

function editCpoOrder(StoreOrder $order, array $payload)
{
    $request = Request::create("/cpo-orders/update/{$order->order_number}", 'PUT', $payload);

    return app(CpoOrdersController::class)->update($request, $order->order_number);
}

/** The errors an edit is refused with, keyed by field. */
function cpoEditErrors(StoreOrder $order, array $payload): array
{
    try {
        editCpoOrder($order, $payload);
    } catch (ValidationException $e) {
        return collect($e->errors())->map(fn ($messages) => $messages[0])->all();
    }

    test()->fail('The edit should have been refused.');
}

function ensaymada(): void
{
    SupplierItems::create([
        'ItemCode' => 'BREAD-02', 'item_name' => 'Ensaymada', 'SupplierCode' => 'GSI-B', 'uom' => 'PC',
        'category' => '', 'brand' => '', 'classification' => '', 'packaging_config' => '', 'config' => 0,
        'cost' => 15, 'is_active' => true,
    ]);
}

it('edits a pending CPO order: store, date, vendor, and its lines with their typed cost', function () {
    [$user, $storeA, $storeB, $vendor, $cpo] = cpoFixture();
    ensaymada();
    $this->actingAs($user);
    $order = placedCpoOrder($user, $storeA, $cpo);
    $line = $order->store_order_items()->sole();

    editCpoOrder($order, [
        'order_date' => '2026-10-21',
        'store_branch_id' => $storeB->id,
        'cpo_vendor_id' => $vendor->id,
        'lines' => [
            ['id' => $line->id, 'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity' => 5, 'cost' => 12.5],
            ['id' => null, 'item_code' => 'BREAD-02', 'uom' => 'PC', 'quantity' => 3, 'cost' => 8],
        ],
    ]);

    $order->refresh();
    $items = $order->store_order_items()->orderBy('id')->get();

    expect(session('message'))->toBe('CPO order CPO-B-00001 was updated.')
        ->and($order->order_status)->toBe(OrderStatus::PENDING->value)
        ->and((int) $order->store_branch_id)->toBe($storeB->id)
        // Moved to another store: that store's next order number, as in Mass Orders.
        ->and($order->order_number)->toBe('CPO-B-00001')
        ->and($order->order_date)->toStartWith('2026-10-21')
        ->and((int) $order->cpo_vendor_id)->toBe($vendor->id)
        ->and($order->cpo_vendor_address)->toBe('Quezon City')
        ->and($items)->toHaveCount(2)
        ->and((int) $items[0]->id)->toBe($line->id)
        ->and((float) $items[0]->quantity_ordered)->toBe(5.0)
        // Approval and receiving start again from what is ordered.
        ->and((float) $items[0]->quantity_approved)->toBe(5.0)
        ->and((float) $items[0]->quantity_commited)->toBe(5.0)
        ->and((float) $items[0]->cost_per_quantity)->toBe(12.5)
        ->and((float) $items[0]->total_cost)->toBe(62.5)
        ->and($items[1]->item_code)->toBe('BREAD-02')
        ->and((float) $items[1]->total_cost)->toBe(24.0);

    // A line left out is taken off the order.
    editCpoOrder($order, [
        'order_date' => '2026-10-21', 'store_branch_id' => $storeB->id, 'cpo_vendor_id' => $vendor->id,
        'lines' => [['id' => $items[1]->id, 'item_code' => 'BREAD-02', 'uom' => 'PC', 'quantity' => 3, 'cost' => 8]],
    ]);

    expect($order->store_order_items()->pluck('item_code')->all())->toBe(['BREAD-02']);
});

it('refuses to edit a CPO order once its last approval committed it, or once receiving started', function () {
    [$user, $storeA, , , $cpo] = cpoFixture();
    $this->actingAs($user);
    $payload = fn ($order) => [
        'order_date' => '2026-10-19', 'store_branch_id' => $storeA->id,
        'lines' => [['id' => $order->store_order_items()->value('id'), 'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity' => 1, 'cost' => 10]],
    ];

    $committed = placedCpoOrder($user, $storeA, $cpo, status: OrderStatus::COMMITTED->value);
    expect(cpoEditErrors($committed, $payload($committed))['error'])->toBe('This order is already committed and can no longer be edited.');

    $redirect = app(CpoOrdersController::class)->edit($committed->order_number);
    expect($redirect->getTargetUrl())->toBe(route('cpo-orders.index'))
        ->and(session('errors')->first('error'))->toBe('This order is already committed and can no longer be edited.');

    $receiving = placedCpoOrder($user, $storeA, $cpo, date: '2026-10-20');
    $receiving->store_order_items()->update(['quantity_received' => 1]);
    expect(cpoEditErrors($receiving, $payload($receiving))['error'])->toContain('Receiving has started');

    expect((float) $committed->store_order_items()->value('quantity_ordered'))->toBe(2.0);
});

it('checks edited lines as an upload checks its rows', function () {
    [$user, $storeA, , , $cpo] = cpoFixture();
    $this->actingAs($user);
    $order = placedCpoOrder($user, $storeA, $cpo);

    $errors = cpoEditErrors($order, [
        'order_date' => '2026-10-19', 'store_branch_id' => $storeA->id,
        'lines' => [
            ['id' => null, 'item_code' => 'NOPE-99', 'uom' => 'PCK', 'quantity' => 1, 'cost' => 5],
            ['id' => null, 'item_code' => 'BREAD-01', 'uom' => 'BOX', 'quantity' => 1, 'cost' => 5],
            ['id' => null, 'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity' => 1, 'cost' => 5],
            ['id' => null, 'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity' => 2, 'cost' => 5],
        ],
    ]);

    expect($errors['lines.0.item_code'])->toBe("'NOPE-99' is not in the CPO list of items.")
        ->and($errors['lines.1.uom'])->toBe("'BOX' is not a unit of this item.")
        ->and($errors['lines.3.uom'])->toBe('This item and unit are already on line 3.');

    // A cost of 0 is refused before anything else.
    expect(cpoEditErrors($order, [
        'order_date' => '2026-10-19', 'store_branch_id' => $storeA->id,
        'lines' => [['id' => null, 'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity' => 1, 'cost' => 0]],
    ])['lines.0.cost'])->toBe('The cost must be greater than 0.');

    expect($order->store_order_items()->count())->toBe(1);
});

it('refuses a store not set up for CPO, a closed date and a second order from the vendor, and keeps the placed vendor details', function () {
    [$user, $storeA, , $vendor, $cpo] = cpoFixture();
    $this->actingAs($user);
    $order = placedCpoOrder($user, $storeA, $cpo, $vendor);
    $lines = [['id' => $order->store_order_items()->value('id'), 'item_code' => 'BREAD-01', 'uom' => 'PCK', 'quantity' => 4, 'cost' => 10]];

    $noCpo = StoreBranch::create(['branch_code' => 'NOCPO', 'brand_code' => 'NOCPO', 'name' => 'No CPO', 'store_status' => 'active', 'is_active' => true]);
    $user->store_branches()->attach($noCpo->id);
    expect(cpoEditErrors($order, ['order_date' => '2026-10-19', 'store_branch_id' => $noCpo->id, 'cpo_vendor_id' => $vendor->id, 'lines' => $lines])['store_branch_id'])
        ->toBe('Choose one of your stores that is set up to order CPO.');

    placedCpoOrder($user, $storeA, $cpo, $vendor, date: '2026-10-22');
    expect(cpoEditErrors($order, ['order_date' => '2026-10-22', 'store_branch_id' => $storeA->id, 'cpo_vendor_id' => $vendor->id, 'lines' => $lines])['error'])
        ->toContain('A CPO order from Corner Market for this store and date already exists');

    // The vendor's list details changed since: the order keeps what it was placed with.
    $vendor->update(['address' => 'Makati']);
    editCpoOrder($order, ['order_date' => '2026-10-19', 'store_branch_id' => $storeA->id, 'cpo_vendor_id' => $vendor->id, 'lines' => $lines]);
    expect($order->refresh()->cpo_vendor_address)->toBe('Quezon City')
        ->and((float) $order->store_order_items()->value('quantity_ordered'))->toBe(4.0);

    // A date the store's final approved month end count closed is refused; the date it has is kept.
    $this->partialMock(\App\Http\Services\MonthEndClosedPeriodService::class, fn ($mock) => $mock
        ->shouldReceive('problem')->andReturnUsing(fn ($storeId, $date) => $date <= '2026-10-31' ? 'Closed.' : null));

    expect(cpoEditErrors($order, ['order_date' => '2026-10-20', 'store_branch_id' => $storeA->id, 'cpo_vendor_id' => $vendor->id, 'lines' => $lines])['order_date'])
        ->toBe('Closed.');
    editCpoOrder($order, ['order_date' => '2026-10-19', 'store_branch_id' => $storeA->id, 'cpo_vendor_id' => null, 'lines' => $lines]);
    expect($order->refresh()->cpo_vendor_id)->toBeNull()
        ->and($order->cpo_vendor_address)->toBeNull();
});

it('closes the edit at the CPO edit cutoff when one is set', function () {
    [$user, $storeA, , , $cpo] = cpoFixture();
    $this->actingAs($user);
    // Placed on a Monday 9 AM; the CPO cutoff is Wednesday 2 PM.
    \App\Models\OrdersCutoff::create(['ordering_template' => 'CPO', 'cutoff_1_day' => 3, 'cutoff_1_time' => '14:00', 'days_covered_1' => 'MONDAY']);
    $order = placedCpoOrder($user, $storeA, $cpo);
    $order->forceFill(['created_at' => '2026-10-19 09:00:00'])->save();

    $service = app(\App\Http\Services\CpoOrderService::class);

    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-21 13:59', 'Asia/Manila'));
    expect($service->editProblem($order->refresh()))->toBeNull();

    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-21 14:00', 'Asia/Manila'));
    expect($service->editProblem($order))->toBe('The edit cutoff for this order passed on Oct 21, 2026 2:00 PM.');

    \Illuminate\Support\Carbon::setTestNow();
});

it('shows the edit button only where the edit would be taken, and searches CPO items from three characters', function () {
    [$user, $storeA, , , $cpo] = cpoFixture();
    ensaymada();
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'edit cpo orders', 'guard_name' => 'web']);
    $user->givePermissionTo('edit cpo orders');
    $this->actingAs($user)->withoutVite(); // the Edit page is not in the built manifest until the next build
    $controller = app(CpoOrdersController::class);

    $pending = placedCpoOrder($user, $storeA, $cpo);
    $committed = placedCpoOrder($user, $storeA, $cpo, status: OrderStatus::COMMITTED->value, date: '2026-10-20');

    $editable = fn () => collect($controller->index(Request::create('/cpo-orders'))->toResponse(Request::create('/'))
        ->getOriginalContent()->getData()['page']['props']['orders']['data'])
        ->mapWithKeys(fn ($order) => [$order['order_number'] => $order['editable']])->all();

    expect($editable())->toEqualCanonicalizing([$committed->order_number => false, $pending->order_number => true]);

    $props = $controller->edit($pending->order_number)->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props'];
    expect($props['lines'][0]['units'])->toContain('PCK')
        ->and($props['lines'][0]['item_name'])->toBe('Pandesal')
        ->and(collect($props['stores'])->pluck('brand_code')->all())->toBe(['CPO-A', 'CPO-B']);

    $search = fn ($q) => collect($controller->items(Request::create('/cpo-orders/items', 'GET', ['q' => $q]))->getData(true));
    expect($search('Pa')->all())->toBe([])
        ->and($search('pandesal')->pluck('item_code')->all())->toBe(['BREAD-01'])
        ->and($search('BREAD')->pluck('item_code')->sort()->values()->all())->toBe(['BREAD-01', 'BREAD-02'])
        ->and($search('ensay')->first()['units'])->toBe(['PC']);

    // Without the CPO template assigned, no order shows the button.
    $user->suppliers()->detach('CPO');
    expect($editable())->toEqualCanonicalizing([$committed->order_number => false, $pending->order_number => false]);
});
