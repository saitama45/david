<?php

use App\Http\Controllers\MassOrdersApprovalController;
use App\Http\Controllers\MassOrdersController;
use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * AO, EO and ALOC order the way CPO did in Mass Orders: any date, any day of the store's
 * schedule, and the commissary catalogue. GSI-B keeps its weekday.
 *
 * @return array{0: User, 1: StoreBranch, 2: StoreBranch}
 */
function unrestrictedTemplateFixture(): array
{
    $user = User::factory()->create();

    foreach (['AO' => 'Additional Order', 'GSI-B' => 'GSI Bakery'] as $code => $name) {
        Supplier::create(['supplier_code' => $code, 'name' => $name, 'is_active' => true, 'is_forapproval_massorders' => false]);
        $user->suppliers()->attach($code);
    }

    SupplierItems::create([
        'ItemCode' => 'BREAD-01', 'item_name' => 'Pandesal', 'SupplierCode' => 'GSI-B', 'uom' => 'PCK',
        'category' => '', 'brand' => '', 'classification' => '', 'packaging_config' => '', 'config' => 0,
        'cost' => 10, 'is_active' => true,
    ]);

    $monday = DeliverySchedule::firstOrCreate(['day' => 'MONDAY']);
    $stores = collect(['AO' => 'AO-STORE', 'GSI-B' => 'GSI-STORE'])->map(function ($code, $variant) use ($user, $monday) {
        $store = StoreBranch::create(['branch_code' => $code, 'brand_code' => $code, 'name' => "Store {$code}", 'store_status' => 'active', 'is_active' => true]);
        $store->delivery_schedules()->attach($monday->id, ['variant' => $variant]);
        $user->store_branches()->attach($store->id);

        return $store;
    });

    return [$user, $stores['AO'], $stores['GSI-B']];
}

/** The heading row of a downloaded template. */
function templateHeaders($response): array
{
    $file = $response->getFile()->getPathname();
    $sheet = IOFactory::load($file)->getActiveSheet();
    $read = [
        $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0],
        $sheet->getCell('D2')->getValue(),
    ];
    @unlink($file); // the download's temp file in storage/framework/cache

    return $read;
}

it('puts a store scheduled for AO on any weekday in the AO template, with the commissary items', function () {
    [$user] = unrestrictedTemplateFixture();
    $this->actingAs($user);
    $wednesday = Carbon::parse('next wednesday')->toDateString();

    [$headers, $firstItem] = templateHeaders(app(MassOrdersController::class)->downloadTemplate(
        Request::create('/mass-orders/download-template', 'GET', ['supplier_code' => 'AO', 'order_date' => $wednesday])
    ));

    expect($headers)->toContain('AO-STORE')
        ->and($firstItem)->toBe('BREAD-01');
});

it('keeps the weekday rule for a regular template', function () {
    [$user] = unrestrictedTemplateFixture();
    $this->actingAs($user);
    $wednesday = Carbon::parse('next wednesday')->toDateString();

    [$headers] = templateHeaders(app(MassOrdersController::class)->downloadTemplate(
        Request::create('/mass-orders/download-template', 'GET', ['supplier_code' => 'GSI-B', 'order_date' => $wednesday])
    ));

    expect($headers)->not->toContain('GSI-STORE');
});

it('takes an AO order for any date, prices it from the commissary catalogue, and sends it for approval only while its switch is on', function (bool $switchOn, string $status) {
    [$user, $aoStore] = unrestrictedTemplateFixture();
    $this->actingAs($user);
    Supplier::where('supplier_code', 'AO')->update(['is_forapproval_massorders' => $switchOn]);
    $farDate = Carbon::now('Asia/Manila')->addDays(200)->toDateString(); // outside the 60-day window

    $sheet = (new Spreadsheet)->getActiveSheet();
    $sheet->fromArray([
        ['Ordering Template', 'Category', 'Classification', 'Item Code', 'Item Name', 'Packaging Config', 'Unit', 'AO-STORE'],
        ['AO', '', '', 'BREAD-01', 'Pandesal', '', 'PCK', 3],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'ao').'.xlsx';
    (new Xlsx($sheet->getParent()))->save($path);

    app(MassOrdersController::class)->uploadMassOrder(Request::create('/mass-orders/upload', 'POST', [
        'supplier_code' => 'AO', 'order_date' => $farDate,
    ], [], ['mass_order_file' => new UploadedFile($path, 'ao.xlsx', null, null, true)]));

    $order = StoreOrder::with('store_order_items')->firstOrFail();

    expect(session('success'))->toBeTrue()
        ->and((int) $order->store_branch_id)->toBe($aoStore->id)
        ->and($order->order_status)->toBe($status)
        // Placed approved, it goes straight to Inbound Orders at its ordered quantity.
        ->and((float) $order->store_order_items->first()->quantity_commited)->toBe(3.0)
        ->and((float) $order->store_order_items->first()->cost_per_quantity)->toBe(10.0)
        ->and((float) $order->store_order_items->first()->total_cost)->toBe(30.0);
})->with([
    'approval on' => [true, 'pending'],
    'approval off' => [false, 'approved'],
]);

it('lists an AO order in Mass Orders Approval, whose approval will commit it, with the switch on', function () {
    [$user, $aoStore] = unrestrictedTemplateFixture();
    $this->actingAs($user);
    $ao = Supplier::where('supplier_code', 'AO')->firstOrFail();
    $ao->update(['is_forapproval_massorders' => true]);

    $order = StoreOrder::create([
        'encoder_id' => $user->id, 'supplier_id' => $ao->id, 'store_branch_id' => $aoStore->id,
        'order_number' => 'AO-STORE-00001', 'order_date' => now()->toDateString(), 'order_status' => 'pending', 'variant' => 'mass regular',
    ]);

    $props = fn ($response) => $response->toResponse(Request::create('/'))->getOriginalContent()->getData()['page']['props'];
    $index = $props(app(MassOrdersApprovalController::class)->index(Request::create('/mass-orders-approval')));
    $show = $props(app(MassOrdersApprovalController::class)->show($order->id));

    expect(collect($index['orders']['data'])->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$order->id])
        ->and($show['commitsOnApproval'])->toBeTrue();
});
