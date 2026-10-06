<?php

use App\Exports\InventoryMovementReportExport;
use App\Http\Controllers\InventoryMovementReportController;
use App\Http\Services\InventoryMovementAdjustmentService;
use App\Http\Services\InventoryMovementService;
use App\Models\Entity;
use App\Models\InventoryMovementAdjustment;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Adjustment column of the Inventory Movement Report: a quantity entered against an
 * item's Variance with the reason for it, and Final Variance = Variance + Adjustment.
 *
 * The controller is called directly where the saved row is read back: every test HTTP
 * request disconnects the database on terminate(), which rolls back RefreshDatabase's
 * transaction.
 */
function movementAdjustmentFixture(): array
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $store = StoreBranch::create(['branch_code' => 'TIMA', 'brand_code' => 'TIMA', 'name' => 'Main Store', 'store_status' => 'Active', 'is_active' => 1]);
    $other = StoreBranch::create(['branch_code' => 'TIMB', 'brand_code' => 'TIMB', 'name' => 'Other Store', 'store_status' => 'Active', 'is_active' => 1]);
    $user->store_branches()->attach($store->id);

    SAPMasterfile::create([
        'ItemCode' => 'RM-COCOA', 'ItemDescription' => 'Powder - Cocoa',
        'AltUOM' => 'Bag', 'BaseUOM' => 'Bag', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
    ]);

    // 4 drinks sold in October, a Bag of cocoa each, and no count: the Variance is 4.
    $drink = POSMasterfile::create(['POSCode' => 'FG-1', 'POSDescription' => 'Mocha Latte', 'SRP' => 180, 'is_active' => true]);
    POSMasterfileBOM::create(['POSCode' => 'FG-1', 'ItemCode' => 'RM-COCOA', 'BOMQty' => 1, 'BOMUOM' => 'Bag']);
    $receiptId = DB::table('store_transactions')->insertGetId([
        'store_branch_id' => $store->id, 'order_date' => '2026-10-03', 'posted' => 'Y', 'tim_number' => 'TIM-1', 'receipt_number' => 'R-100',
    ]);
    DB::table('store_transaction_items')->insert([
        'store_transaction_id' => $receiptId, 'product_id' => $drink->id, 'base_quantity' => 4, 'quantity' => 4,
        'price' => 180, 'discount' => 0, 'line_total' => 720, 'net_total' => 720,
    ]);

    return ['entity' => $entity, 'user' => $user, 'store' => $store, 'other' => $other];
}

/** The cocoa row as the report builds it for the store and the dates. */
function adjustedCocoaRow(array $f, string $from = '2026-10-01', string $to = '2026-10-06'): array
{
    $filters = ['branch_id' => $f['store']->id, 'date_from' => $from, 'date_to' => $to];

    return collect(app(InventoryMovementAdjustmentService::class)->apply(
        app(InventoryMovementService::class)->movementData(SAPMasterfile::where('is_active', true)->get(), $filters),
        $f['store']->id,
        $filters
    ))->firstWhere('sap_code', 'RM-COCOA');
}

function saveCocoaAdjustment(array $f, array $input = [])
{
    return app(InventoryMovementReportController::class)->saveAdjustment(
        Request::create('/reports/inventory-movement/adjustment', 'POST', array_merge([
            'branch_id' => $f['store']->id,
            'date_to' => '2026-10-06',
            'sap_code' => 'RM-COCOA',
            'quantity' => -4,
            'reason' => 'Four bags were given to the commissary and not recorded.',
        ], $input)),
        app(InventoryMovementAdjustmentService::class)
    );
}

function allowAdjusting(User $user): void
{
    Permission::findOrCreate('adjust inventory movement variance');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo('adjust inventory movement variance');
}

it('shows no adjustment and a Final Variance equal to the Variance until one is entered', function () {
    $row = adjustedCocoaRow(movementAdjustmentFixture());

    expect($row['variance_qty'])->toBe(4.0);
    expect($row['adjustment_qty'])->toBe(0.0);
    expect($row['adjustment_reason'])->toBeNull();
    expect($row['final_variance_qty'])->toBe(4.0);
});

it('adds a saved adjustment to the Variance and keeps its reason and who entered it', function () {
    $f = movementAdjustmentFixture();
    $this->actingAs($f['user']);

    $saved = saveCocoaAdjustment($f)->getData(true)['adjustment'];

    expect($saved['adjustment_qty'])->toEqual(-4);
    expect($saved['adjustment_reason'])->toBe('Four bags were given to the commissary and not recorded.');

    $row = adjustedCocoaRow($f);

    expect($row['variance_qty'])->toBe(4.0);
    expect($row['adjustment_qty'])->toBe(-4.0);
    expect($row['adjustment_reason'])->toBe('Four bags were given to the commissary and not recorded.');
    expect($row['adjustment_by'])->toBe($f['user']->full_name);
    expect($row['adjustment_at'])->not->toBeNull();
    expect($row['final_variance_qty'])->toBe(0.0);

    // It is the store's own, in the unit the report shows the item in.
    $stored = InventoryMovementAdjustment::first();
    expect($stored->entity_id)->toEqual($f['entity']->id);
    expect($stored->uom)->toBe('Bag');
    expect([$stored->year, $stored->month])->toBe([2026, 10]);
});

it('keeps an adjustment with the month of the To Date, whatever the dates inside it', function () {
    $f = movementAdjustmentFixture();
    $this->actingAs($f['user']);
    saveCocoaAdjustment($f, ['quantity' => -1.5]);

    // Any report that ends in October carries it.
    expect(adjustedCocoaRow($f, '2026-10-01', '2026-10-31')['adjustment_qty'])->toBe(-1.5);
    expect(adjustedCocoaRow($f, '2026-09-15', '2026-10-20')['adjustment_qty'])->toBe(-1.5);

    // September and November have none.
    expect(adjustedCocoaRow($f, '2026-09-01', '2026-09-30')['adjustment_qty'])->toBe(0.0);
    expect(adjustedCocoaRow($f, '2026-10-01', '2026-11-05')['adjustment_qty'])->toBe(0.0);
});

it('replaces the adjustment of the same store, item and month instead of adding another', function () {
    $f = movementAdjustmentFixture();
    $this->actingAs($f['user']);

    saveCocoaAdjustment($f, ['quantity' => -4, 'date_to' => '2026-10-06']);
    saveCocoaAdjustment($f, ['quantity' => 0, 'reason' => 'Entered by mistake.', 'date_to' => '2026-10-28']);

    expect(InventoryMovementAdjustment::count())->toBe(1);

    $row = adjustedCocoaRow($f);

    expect($row['adjustment_qty'])->toBe(0.0);
    expect($row['adjustment_reason'])->toBe('Entered by mistake.');
    expect($row['final_variance_qty'])->toBe(4.0);
});

it('refuses an adjustment without a reason or without a quantity', function (array $input, string $field) {
    $f = movementAdjustmentFixture();
    $this->actingAs($f['user']);

    try {
        saveCocoaAdjustment($f, $input);
        $this->fail('The adjustment was saved.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(InventoryMovementAdjustment::count())->toBe(0);
})->with([
    'no reason' => [['reason' => null], 'reason'],
    'a reason over 500 characters' => [['reason' => str_repeat('a', 501)], 'reason'],
    'no quantity' => [['quantity' => null], 'quantity'],
    'a quantity that is not a number' => [['quantity' => 'four'], 'quantity'],
]);

it('saves only for a store the user is assigned to', function (string $storeKey, int $status) {
    $f = movementAdjustmentFixture();
    allowAdjusting($f['user']);

    $this->actingAs($f['user'])->postJson(route('reports.inventory-movement.adjustment.save'), [
        'branch_id' => $f[$storeKey]->id,
        'date_to' => '2026-10-06',
        'sap_code' => 'RM-COCOA',
        'quantity' => -4,
        'reason' => 'Four bags were given to the commissary and not recorded.',
    ])->assertStatus($status);
})->with([['store', 200], ['other', 403]]);

it('refuses a user who may read the report but not adjust it', function () {
    $f = movementAdjustmentFixture();
    Permission::findOrCreate('view inventory movement report');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view inventory movement report');

    $this->actingAs($f['user'])->postJson(route('reports.inventory-movement.adjustment.save'), [
        'branch_id' => $f['store']->id,
        'date_to' => '2026-10-06',
        'sap_code' => 'RM-COCOA',
        'quantity' => -4,
        'reason' => 'Four bags were given to the commissary and not recorded.',
    ])->assertStatus(403);
});

it('writes the adjustment, the Final Variance and the reason to the Excel export', function () {
    $f = movementAdjustmentFixture();
    $this->actingAs($f['user']);
    saveCocoaAdjustment($f, ['quantity' => -1.5]);

    $path = tempnam(sys_get_temp_dir(), 'ima').'.xlsx';
    file_put_contents($path, Excel::raw(new InventoryMovementReportExport(
        [adjustedCocoaRow($f)],
        ['date_from' => '2026-10-01', 'date_to' => '2026-10-06'],
        $f['store'],
        null,
        'QA Tester',
        '2026-10-06 15:16:42'
    ), \Maatwebsite\Excel\Excel::XLSX));
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('N4')->getValue())->toBe('FINAL BALANCE');
    expect([$sheet->getCell('Q5')->getValue(), $sheet->getCell('R5')->getValue(), $sheet->getCell('S5')->getValue()])
        ->toBe(['Adjustment', 'Final Variance', 'Adjustment Reason']);

    expect($sheet->getCell('P6')->getValue())->toBe(4.0);
    expect($sheet->getCell('Q6')->getValue())->toBe(-1.5);
    expect($sheet->getCell('R6')->getValue())->toBe(2.5);
    expect($sheet->getCell('R6')->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0.0000');
    expect($sheet->getCell('S6')->getValue())->toBe('Four bags were given to the commissary and not recorded.');

    // A long reason wraps in its column instead of stretching it.
    expect($sheet->getColumnDimension('S')->getWidth())->toBe(45.0);
    expect($sheet->getStyle('S6')->getAlignment()->getWrapText())->toBeTrue();

    unlink($path);
});
