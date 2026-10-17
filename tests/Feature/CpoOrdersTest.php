<?php

use App\Enum\OrderStatus;
use App\Http\Controllers\CpoOrdersController;
use App\Http\Controllers\MassOrdersApprovalController;
use App\Http\Controllers\MassOrdersController;
use App\Models\CpoOrderAttachment;
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

it('creates one pending CPO order per store with the typed cost, the vendor and the invoice', function () {
    [$user, $storeA, $storeB, $vendor] = cpoFixture();
    $this->actingAs($user);

    uploadCpoOrder(
        ['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id],
        [
            'mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', 'Pandesal', '', 'PCK', 12.5, 2, 3]]),
            'attachments' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
        ]
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
            ->and($order->cpoAttachments)->toHaveCount(1)
            ->and((float) $order->store_order_items->first()->cost_per_quantity)->toBe(12.5);
    }

    $lineA = $orders->firstWhere('store_branch_id', $storeA->id)->store_order_items->first();
    expect((float) $lineA->quantity_ordered)->toBe(2.0)
        ->and((float) $lineA->total_cost)->toBe(25.0)
        ->and($lineA->uom)->toBe('PCK');

    // One file, linked to both orders.
    $path = $orders->first()->cpoAttachments->first()->file_path;
    expect($orders->last()->cpoAttachments->first()->file_path)->toBe($path);
    Storage::disk(CpoOrderAttachment::DISK)->assertExists($path);
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
            'attachments' => [UploadedFile::fake()->create('receipt.jpg', 50, 'image/jpeg')],
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

it('requires the vendor and an invoice or receipt', function () {
    [$user] = cpoFixture();
    $this->actingAs($user);

    try {
        uploadCpoOrder(['order_date' => '2026-10-19'], ['mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', '', '', 'PCK', 5, 1, '']])]);
        $this->fail('The upload should have been refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['cpo_vendor_id', 'attachments'])
            ->and($e->errors()['attachments'][0])->toBe('Attach the invoice or receipt.');
    }
});

it('skips a store that already has an open order from the vendor that day, but not after a rejection', function () {
    [$user, $storeA, , $vendor] = cpoFixture();
    $this->actingAs($user);

    $upload = fn () => uploadCpoOrder(
        ['order_date' => '2026-10-19', 'cpo_vendor_id' => $vendor->id],
        [
            'mass_order_file' => cpoOrderFile([['CPO', '', '', 'BREAD-01', '', '', 'PCK', 5, 1, '']]),
            'attachments' => [UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')],
        ]
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
    $user->suppliers()->attach('CPO');
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
