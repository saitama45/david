<?php

use App\Http\Controllers\POSMasterfileController;
use App\Http\Controllers\SAPMasterfileController;
use App\Http\Controllers\SupplierItemsController;
use App\Http\Services\SapItemTypeService;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\SapItemType;
use App\Models\SAPMasterfile;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-by-one create on the SAP, Supplier Items and POS masterfile lists.
 * Runs on an isolated test database. Nothing here deletes a record.
 */
function masterfileFixture(): array
{
    // Fresh migrations create a UNIQUE index on sap_masterfiles.ItemCode that the
    // live schema does not have (one row per AltUOM). Match the live schema.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $permissions = ['view sapitems list', 'create sapitems', 'view SupplierItems list', 'create SupplierItems',
        'view POSMasterfile list', 'create POSMasterfile'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    $supplier = Supplier::create(['supplier_code' => 'GSI-P', 'name' => 'GSI OT-PR', 'is_active' => true]);
    Supplier::create(['supplier_code' => 'PUL-O', 'name' => 'Not Assigned', 'is_active' => true]);
    $user->suppliers()->attach($supplier->supplier_code);

    return compact('entity', 'user');
}

function createSap(array $input)
{
    return app(SAPMasterfileController::class)->store(Request::create('/sapitems-list/store', 'POST', array_merge([
        'ItemDescription' => 'Test item', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => 1,
    ], $input)), app(SapItemTypeService::class));
}

function createSupplierItem(array $input)
{
    return app(SupplierItemsController::class)->store(Request::create('/SupplierItems-list/store', 'POST', array_merge([
        'SupplierCode' => 'GSI-P', 'cost' => 10, 'is_active' => 1,
    ], $input)));
}

function validationErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('creates a SAP row in the active entity and gives its item code the chosen type', function () {
    ['entity' => $entity] = masterfileFixture();
    $food = SapItemType::create(['name' => 'FOOD', 'is_active' => true]);

    createSap(['ItemCode' => ' 191A2A ', 'AltUOM' => 'KG', 'BaseUOM' => 'KG', 'sap_item_type_id' => $food->id]);

    $row = SAPMasterfile::where('ItemCode', '191A2A')->sole();
    expect((int) $row->entity_id)->toBe($entity->id)
        ->and($row->AltUOM)->toBe('KG')
        ->and(app(SapItemTypeService::class)->typeIdFor($entity->id, '191A2A'))->toBe($food->id);
});

it('refuses a SAP row that is already on file, blank base included', function () {
    masterfileFixture();
    SAPMasterfile::create(['ItemCode' => 'A1', 'AltUOM' => 'CASE', 'BaseUOM' => 'CAN', 'AltQty' => 1, 'BaseQty' => 48, 'is_active' => 1]);
    SAPMasterfile::create(['ItemCode' => 'B1', 'AltUOM' => 'PC', 'BaseUOM' => null, 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => 1]);

    expect(validationErrors(fn () => createSap(['ItemCode' => 'A1', 'AltUOM' => 'case', 'BaseUOM' => 'Can'])))->toHaveKey('AltUOM')
        ->and(validationErrors(fn () => createSap(['ItemCode' => 'B1', 'AltUOM' => 'PC', 'BaseUOM' => 'PC'])))->toHaveKey('AltUOM');
});

it('refuses a second base unit for an item, but lets an existing pack be restated in one', function () {
    masterfileFixture();
    SAPMasterfile::create(['ItemCode' => 'S1', 'AltUOM' => 'CAN', 'BaseUOM' => 'CAN', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => 1]);
    SAPMasterfile::create(['ItemCode' => 'S1', 'AltUOM' => 'CASE', 'BaseUOM' => 'CAN', 'AltQty' => 1, 'BaseQty' => 48, 'is_active' => 1]);

    expect(validationErrors(fn () => createSap(['ItemCode' => 'S1', 'AltUOM' => 'LIT', 'BaseUOM' => 'LIT'])))->toHaveKey('BaseUOM')
        ->and(validationErrors(fn () => createSap(['ItemCode' => 'S1', 'AltUOM' => 'CASE', 'BaseUOM' => 'GM', 'BaseQty' => 18720])))->toBe([])
        ->and(SAPMasterfile::where('ItemCode', 'S1')->count())->toBe(3);
});

it('creates a supplier item in SAP spelling, naming it from SAP and storing blanks as the import does', function () {
    ['entity' => $entity, 'user' => $user] = masterfileFixture();
    $this->actingAs($user);
    SAPMasterfile::create(['ItemCode' => '872A2C', 'ItemDescription' => 'Bistro - Butter unsalted 500g', 'AltUOM' => 'PACK(.5)', 'BaseUOM' => 'KG', 'AltQty' => 1, 'BaseQty' => 0.5, 'is_active' => 1]);

    // Blank inputs reach the controller as null (ConvertEmptyStringsToNull).
    createSupplierItem(['ItemCode' => '872A2C', 'uom' => 'pack(.5)', 'item_name' => null, 'category' => null,
        'brand' => null, 'classification' => 'CONTROL', 'packaging_config' => null, 'config' => null]);

    $item = SupplierItems::where('ItemCode', '872A2C')->sole();
    expect((int) $item->entity_id)->toBe($entity->id)
        ->and($item->uom)->toBe('PACK(.5)')
        ->and($item->item_name)->toBe('Bistro - Butter unsalted 500g')
        ->and($item->classification)->toBe('CONTROL')
        ->and($item->category)->toBe('');
});

it('refuses a supplier item for an unassigned supplier, an unknown unit, or one already listed', function () {
    ['user' => $user] = masterfileFixture();
    $this->actingAs($user);
    SAPMasterfile::create(['ItemCode' => 'X1', 'ItemDescription' => 'X', 'AltUOM' => 'PC', 'BaseUOM' => 'PC', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => 1]);
    createSupplierItem(['ItemCode' => 'X1', 'uom' => 'PC']);

    expect(validationErrors(fn () => createSupplierItem(['ItemCode' => 'X1', 'uom' => 'PC', 'SupplierCode' => 'PUL-O'])))->toHaveKey('SupplierCode')
        ->and(validationErrors(fn () => createSupplierItem(['ItemCode' => 'X1', 'uom' => 'BOX'])))->toHaveKey('uom')
        ->and(validationErrors(fn () => createSupplierItem(['ItemCode' => 'NOPE', 'uom' => 'PC'])))->toHaveKey('ItemCode')
        ->and(validationErrors(fn () => createSupplierItem(['ItemCode' => 'X1', 'uom' => 'pc'])))->toHaveKey('ItemCode')
        ->and(SupplierItems::where('ItemCode', 'X1')->count())->toBe(1);
});

it('creates a POS item and refuses its code a second time in the same entity', function () {
    ['entity' => $entity] = masterfileFixture();
    $store = fn (array $input) => app(POSMasterfileController::class)->store(Request::create('/POSMasterfile-list/store', 'POST', array_merge([
        'POSDescription' => 'Carbonara', 'Category' => 'Food', 'SRP' => 250, 'is_active' => 1,
    ], $input)));

    $store(['POSCode' => 'P100']);

    expect((int) POSMasterfile::where('POSCode', 'P100')->sole()->entity_id)->toBe($entity->id)
        ->and(validationErrors(fn () => $store(['POSCode' => 'P100'])))->toHaveKey('POSCode');
});

it('opens each create page for a user with its create permission', function (string $route, string $component) {
    ['user' => $user] = masterfileFixture();

    $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'SAP' => ['sapitems.create', 'SAPMasterfileItem/Create'],
    'Supplier Items' => ['SupplierItems.create', 'SupplierItems/Create'],
    'POS' => ['POSMasterfile.create', 'POSMasterfile/Create'],
]);

it('refuses each create page to a user who may only view the list', function (string $route, string $viewPermission) {
    masterfileFixture();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo($viewPermission);

    $this->actingAs($viewer)->get(route($route))->assertForbidden();
})->with([
    'SAP' => ['sapitems.create', 'view sapitems list'],
    'Supplier Items' => ['SupplierItems.create', 'view SupplierItems list'],
    'POS' => ['POSMasterfile.create', 'view POSMasterfile list'],
]);
