<?php

use App\Exports\InventoryMovementReportExport;
use App\Http\Controllers\InventoryMovementReportController;
use App\Http\Controllers\SapItemTypeController;
use App\Http\Services\InventoryMovementService;
use App\Models\Entity;
use App\Models\SapItemType;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * An Item Type is INVENTORIABLE or NON-INVENTORIABLE, and the Inventory Movement Report lists
 * one class at a time, INVENTORIABLE unless asked. The Qty / Cost Variance side of this is in
 * QtyVarianceCostVarianceReportTest. Controllers are called directly: every test HTTP request
 * disconnects the database on terminate(), which rolls back RefreshDatabase's transaction.
 */
function inventoryClassFixture(): array
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $branch = StoreBranch::create(['branch_code' => 'ICT', 'name' => 'Class Test', 'store_status' => 'active']);
    $user->store_branches()->attach($branch->id);

    // BEANS is food, MIXER is capex, LOOSE has no Item Type at all. All three moved in the period.
    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'ICS', 'name' => 'Supplier', 'is_active' => true]);
    $orderId = DB::table('store_orders')->insertGetId([
        'encoder_id' => $user->id, 'supplier_id' => $supplierId, 'store_branch_id' => $branch->id,
        'order_number' => 'ICT-00001', 'order_date' => '2026-10-05', 'order_status' => 'received',
    ]);

    foreach (['BEANS', 'MIXER', 'LOOSE'] as $code) {
        SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $code, 'AltUOM' => 'Pc', 'BaseUOM' => 'Pc',
            'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
        ]);
        DB::table('store_order_items')->insert([
            'store_order_id' => $orderId, 'item_code' => $code, 'uom' => 'Pc',
            'quantity_ordered' => 1, 'quantity_approved' => 1, 'quantity_commited' => 1, 'quantity_received' => 1,
            'cost_per_quantity' => 0, 'total_cost' => 0,
        ]);
    }

    $capex = SapItemType::create(['name' => 'CAPEX', 'inventory_class' => SapItemType::NON_INVENTORIABLE, 'is_active' => true]);
    $food = SapItemType::create(['name' => 'FOOD', 'is_active' => true]);

    foreach (['BEANS' => $food, 'MIXER' => $capex] as $code => $type) {
        DB::table('sap_item_type_assignments')->insert(['entity_id' => $entity->id, 'item_code' => $code, 'sap_item_type_id' => $type->id]);
    }

    return compact('user', 'branch', 'capex', 'food');
}

function inventoryClassRequest(array $f, array $query = []): Request
{
    test()->actingAs($f['user']);

    return Request::create('/reports/inventory-movement', 'GET', $query + [
        'branch_id' => $f['branch']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31',
    ], [], [], ['HTTP_X_INERTIA' => 'true']);
}

/** The report page's rows keyed by item code, and its props. */
function inventoryClassPage(array $f, array $query = []): array
{
    $request = inventoryClassRequest($f, $query);
    $props = app(InventoryMovementReportController::class)->index($request)->toResponse($request)->getData(true)['props'];

    return [collect($props['movementData'])->keyBy('sap_code')->all(), $props];
}

function inventoryClassTypeRequest(array $input): Request
{
    return Request::create('/sapitems-list/types', 'POST', $input);
}

it('starts a type INVENTORIABLE and counting in totals when nothing is given, so nothing changes by itself', function () {
    $food = inventoryClassFixture()['food']->fresh();

    expect($food->inventory_class)->toBe(SapItemType::INVENTORIABLE)
        ->and($food->counts_in_totals)->toBeTrue();
});

it('saves the Inventory Class and Count in Report Totals chosen when a type is created and edited', function () {
    inventoryClassFixture();
    $controller = app(SapItemTypeController::class);

    $controller->store(inventoryClassTypeRequest(['name' => 'smallwares', 'inventory_class' => SapItemType::NON_INVENTORIABLE, 'counts_in_totals' => 1]));
    $type = SapItemType::where('name', 'SMALLWARES')->firstOrFail();
    expect($type->inventory_class)->toBe(SapItemType::NON_INVENTORIABLE)
        ->and($type->counts_in_totals)->toBeTrue();

    $controller->update(inventoryClassTypeRequest(['name' => 'SMALLWARES', 'inventory_class' => SapItemType::INVENTORIABLE, 'counts_in_totals' => 0, 'is_active' => 1]), $type->id);
    expect($type->fresh()->inventory_class)->toBe(SapItemType::INVENTORIABLE)
        ->and($type->fresh()->counts_in_totals)->toBeFalse();
});

it('takes only the two Inventory Classes and a yes or no for totals, and requires both', function (array $input) {
    inventoryClassFixture();

    expect(fn () => app(SapItemTypeController::class)->store(inventoryClassTypeRequest(['name' => 'SMALLWARES'] + $input)))
        ->toThrow(ValidationException::class);
})->with([
    'another class' => [['inventory_class' => 'ASSET', 'counts_in_totals' => 1]],
    'no class' => [['counts_in_totals' => 1]],
    'another totals value' => [['inventory_class' => 'INVENTORIABLE', 'counts_in_totals' => 'maybe']],
    'no totals value' => [['inventory_class' => 'INVENTORIABLE']],
]);

it('lists INVENTORIABLE by default, with an item that has no Item Type on it', function () {
    [$rows, $props] = inventoryClassPage(inventoryClassFixture());

    expect(array_keys($rows))->toEqualCanonicalizing(['BEANS', 'LOOSE'])
        ->and($rows['BEANS']['item_type'])->toBe('FOOD')
        ->and($rows['LOOSE']['item_type'])->toBeNull()
        ->and($props['filters']['inventory_class'])->toBe(SapItemType::INVENTORIABLE)
        ->and($props['sapItems']['total'])->toBe(2);
});

it('lists only the types marked NON-INVENTORIABLE when asked', function () {
    [$rows] = inventoryClassPage(inventoryClassFixture(), ['inventory_class' => SapItemType::NON_INVENTORIABLE]);

    expect(array_keys($rows))->toBe(['MIXER'])
        ->and($rows['MIXER']['item_type'])->toBe('CAPEX');
});

it('moves a type to the INVENTORIABLE list when it is marked so, as SMALLWARES and CAPEX are meant to be', function () {
    $f = inventoryClassFixture();
    $f['capex']->update(['inventory_class' => SapItemType::INVENTORIABLE]);

    [$rows] = inventoryClassPage($f);

    expect(array_keys($rows))->toEqualCanonicalizing(['BEANS', 'LOOSE', 'MIXER']);
});

it('narrows the list by part of the Item Type, inside the class selected', function () {
    $f = inventoryClassFixture();

    expect(array_keys(inventoryClassPage($f, ['filter_item_type' => 'foo'])[0]))->toBe(['BEANS'])
        // CAPEX is on the other list, so it is not found from this one.
        ->and(inventoryClassPage($f, ['filter_item_type' => 'capex'])[0])->toBeEmpty()
        ->and(array_keys(inventoryClassPage($f, ['filter_item_type' => 'capex', 'inventory_class' => SapItemType::NON_INVENTORIABLE])[0]))->toBe(['MIXER']);
});

it('reads the type of the active entity only, when another entity classes the same item code differently', function () {
    $f = inventoryClassFixture();
    $other = Entity::create(['name' => 'Other Entity', 'code' => 'OE'.uniqid(), 'is_active' => true]);
    $otherType = SapItemType::withoutEntityScope()->create([
        'entity_id' => $other->id, 'name' => 'CAPEX', 'inventory_class' => SapItemType::NON_INVENTORIABLE, 'is_active' => true,
    ]);
    DB::table('sap_item_type_assignments')->insert(['entity_id' => $other->id, 'item_code' => 'LOOSE', 'sap_item_type_id' => $otherType->id]);

    [$rows] = inventoryClassPage($f);

    expect($rows)->toHaveKey('LOOSE')
        ->and($rows['LOOSE']['item_type'])->toBeNull();
});

it('falls back to the default list on an unknown class', function () {
    [$rows, $props] = inventoryClassPage(inventoryClassFixture(), ['inventory_class' => 'ASSET']);

    expect($props['filters']['inventory_class'])->toBe(SapItemType::INVENTORIABLE)
        ->and(array_keys($rows))->toEqualCanonicalizing(['BEANS', 'LOOSE']);
});

it('exports the list selected, and says on the sheet which one it is', function () {
    $f = inventoryClassFixture();
    $controller = app(InventoryMovementReportController::class);
    $build = new ReflectionMethod($controller, 'buildExportReport');

    $default = $build->invoke($controller, inventoryClassRequest($f));
    expect(collect($default['movementData'])->pluck('sap_code')->all())->toEqualCanonicalizing(['BEANS', 'LOOSE']);

    $report = $build->invoke($controller, inventoryClassRequest($f, ['inventory_class' => SapItemType::NON_INVENTORIABLE]));
    expect(collect($report['movementData'])->pluck('sap_code')->all())->toBe(['MIXER']);

    $path = tempnam(sys_get_temp_dir(), 'ict').'.xlsx';
    file_put_contents($path, Excel::raw(new InventoryMovementReportExport(
        $report['movementData'], $report['filters'], $report['branch'], null, 'QA Tester', $report['generatedAt']
    ), \Maatwebsite\Excel\Excel::XLSX));
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('A3')->getValue())->toContain('Inventory Class: NON-INVENTORIABLE')
        ->and($sheet->getCell('D5')->getValue())->toBe('Item Type')
        ->and($sheet->getCell('D6')->getValue())->toBe('CAPEX');

    unlink($path);
});

it('leaves the movement service reading every item, for the Month End Count template', function () {
    $f = inventoryClassFixture();

    $rows = app(InventoryMovementService::class)->movementData(SAPMasterfile::where('is_active', true)->get(), [
        'branch_id' => $f['branch']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31',
    ]);

    expect(array_column($rows, 'sap_code'))->toEqualCanonicalizing(['BEANS', 'LOOSE', 'MIXER']);
});
