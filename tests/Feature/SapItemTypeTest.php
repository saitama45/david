<?php

use App\Http\Services\SapItemTypeService;
use App\Imports\SAPMasterfileImport;
use App\Models\Entity;
use App\Models\SapItemType;
use App\Models\SAPMasterfile;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * SAP Item Types: one managed type per ItemCode, shared by all its UOM rows.
 * Runs on the isolated daviddb_test database. Nothing here deletes a record.
 */
function sapTypeFixture(array $permissions = ['view sapitems list', 'edit sapitems', 'manage sapitem types']): array
{
    // Fresh migrations still create a UNIQUE index on sap_masterfiles.ItemCode
    // (2025_07_07's dropUnique is commented out), but the live schema has none:
    // an item code has one row per AltUOM. Match the live schema, or no test can
    // hold two UOM rows. DDL is transactional here, so RefreshDatabase undoes it.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    foreach (['view sapitems list', 'edit sapitems', 'manage sapitem types'] as $name) {
        Permission::findOrCreate($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    $food = SapItemType::create(['name' => 'FOOD', 'is_active' => true]);
    $supplies = SapItemType::create(['name' => 'OPERATING SUPPLIES', 'is_active' => true]);
    $retired = SapItemType::create(['name' => 'OLD TYPE', 'is_active' => false]);

    return compact('entity', 'user', 'food', 'supplies', 'retired');
}

function sapRow(string $code, string $altUom, string $baseUom = 'PC'): SAPMasterfile
{
    return SAPMasterfile::create([
        'ItemCode' => $code, 'ItemDescription' => "Item {$code}", 'AltQty' => 1, 'BaseQty' => 1,
        'AltUOM' => $altUom, 'BaseUOM' => $baseUom, 'is_active' => 1,
    ]);
}

/** Writes a CSV upload and runs it through the real importer. */
function runSapImport(int $entityId, array $headings, array $rows): SAPMasterfileImport
{
    $path = tempnam(sys_get_temp_dir(), 'sap').'.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, $headings);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    SAPMasterfileImport::resetSeenCombinations();
    $import = new SAPMasterfileImport($entityId);
    Excel::import($import, $path);

    return $import;
}

function typeOf(int $entityId, string $itemCode): ?int
{
    return app(SapItemTypeService::class)->typeIdFor($entityId, $itemCode);
}

it('filters the list by type and by uncategorised', function () {
    ['entity' => $entity, 'user' => $user, 'food' => $food] = sapTypeFixture();
    sapRow('FOOD1', 'PC');
    sapRow('NONE1', 'PC');
    app(SapItemTypeService::class)->assign($entity->id, 'FOOD1', $food->id);

    $this->actingAs($user)->get(route('sapitems.index', ['type' => (string) $food->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SAPMasterfileItem/Index')
            ->where('items.data', fn ($items) => collect($items)->pluck('ItemCode')->all() === ['FOOD1']
                && collect($items)->first()['sap_item_type_name'] === 'FOOD')
            ->where('filters.type', (string) $food->id));
});

it('lists uncategorised items when asked', function () {
    ['entity' => $entity, 'user' => $user, 'food' => $food] = sapTypeFixture();
    sapRow('FOOD1', 'PC');
    sapRow('NONE1', 'PC');
    app(SapItemTypeService::class)->assign($entity->id, 'FOOD1', $food->id);

    $this->actingAs($user)->get(route('sapitems.index', ['type' => 'uncategorised']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.data', fn ($items) => collect($items)->pluck('ItemCode')->all() === ['NONE1']));
});

it('sets the type once for every UOM row of the item code', function () {
    ['user' => $user, 'supplies' => $supplies] = sapTypeFixture();
    $pc = sapRow('647A2A', 'PC');
    sapRow('647A2A', 'CASE(12)');

    // Called directly rather than over HTTP: AppServiceProvider disconnects the
    // database when a request terminates, which rolls back RefreshDatabase's
    // transaction - the rows would be gone before we could look at them.
    $request = Illuminate\Http\Request::create(route('sapitems.update', $pc->id), 'PUT', [
        'ItemCode' => '647A2A', 'BaseUOM' => 'PC', 'AltUOM' => 'PC', 'sap_item_type_id' => $supplies->id,
    ]);
    $request->setUserResolver(fn () => $user);
    app(App\Http\Controllers\SAPMasterfileController::class)->update($request, $pc->id, app(SapItemTypeService::class));

    $types = SAPMasterfile::withItemType()->where('ItemCode', '647A2A')->pluck('sap_item_type_name', 'AltUOM');
    expect($types->all())->toBe(['PC' => 'OPERATING SUPPLIES', 'CASE(12)' => 'OPERATING SUPPLIES'])
        ->and(DB::table('sap_item_type_assignments')->where('item_code', '647A2A')->count())->toBe(1);
});

it('refuses a deactivated type for an item that does not already have it', function () {
    ['user' => $user, 'retired' => $retired] = sapTypeFixture();
    $row = sapRow('ITEM1', 'PC');

    $this->actingAs($user)->put(route('sapitems.update', $row->id), [
        'ItemCode' => 'ITEM1', 'BaseUOM' => 'PC', 'AltUOM' => 'PC', 'sap_item_type_id' => $retired->id,
    ])->assertSessionHasErrors('sap_item_type_id');
});

it('refuses a type that belongs to another entity', function () {
    ['user' => $user] = sapTypeFixture();
    $row = sapRow('ITEM1', 'PC');
    $other = Entity::create(['name' => 'Other', 'code' => 'OT'.uniqid(), 'is_active' => true]);
    $foreign = SapItemType::withoutEntityScope()->create(['entity_id' => $other->id, 'name' => 'FOOD', 'is_active' => true]);

    $this->actingAs($user)->put(route('sapitems.update', $row->id), [
        'ItemCode' => 'ITEM1', 'BaseUOM' => 'PC', 'AltUOM' => 'PC', 'sap_item_type_id' => $foreign->id,
    ])->assertSessionHasErrors('sap_item_type_id');
});

it('leaves types alone when the upload has no Item Type column or a blank one', function () {
    ['entity' => $entity, 'food' => $food] = sapTypeFixture();
    sapRow('KEEP1', 'PC');
    sapRow('KEEP2', 'PC');
    $service = app(SapItemTypeService::class);
    $service->assign($entity->id, 'KEEP1', $food->id);
    $service->assign($entity->id, 'KEEP2', $food->id);

    runSapImport($entity->id, ['Item No.', 'Item Description', 'AltUom', 'BaseUom'], [['KEEP1', 'Renamed', 'PC', 'PC']]);
    runSapImport($entity->id, ['Item No.', 'Item Description', 'AltUom', 'BaseUom', 'Item Type'], [['KEEP2', 'Renamed', 'PC', 'PC', '']]);

    expect(typeOf($entity->id, 'KEEP1'))->toBe($food->id)
        ->and(typeOf($entity->id, 'KEEP2'))->toBe($food->id)
        ->and(SAPMasterfile::where('ItemCode', 'KEEP1')->value('ItemDescription'))->toBe('Renamed');
});

it('assigns a known type however it is typed, and new UOM rows inherit it', function () {
    ['entity' => $entity, 'supplies' => $supplies] = sapTypeFixture();

    runSapImport($entity->id, ['Item No.', 'Item Description', 'AltUom', 'BaseUom', 'Item Type'], [
        ['NEW1', 'Gloves', 'PC', 'PC', '  operating supplies '],
    ]);
    // A later upload adds another UOM row for the same code, with no type column.
    runSapImport($entity->id, ['Item No.', 'Item Description', 'AltUom', 'BaseUom'], [['NEW1', 'Gloves', 'BOX(100)', 'PC']]);

    $rows = SAPMasterfile::withItemType()->where('ItemCode', 'NEW1')->orderBy('AltUOM')->pluck('sap_item_type_name', 'AltUOM');

    // Both UOM rows must exist, or "inherits" would pass on the first row alone.
    expect($rows->all())->toBe(['BOX(100)' => 'OPERATING SUPPLIES', 'PC' => 'OPERATING SUPPLIES'])
        ->and(typeOf($entity->id, 'NEW1'))->toBe($supplies->id);
});

it('imports the row but never invents a type for an unknown name', function () {
    ['entity' => $entity] = sapTypeFixture();
    $typesBefore = SapItemType::count();

    $import = runSapImport($entity->id, ['Item No.', 'Item Description', 'AltUom', 'BaseUom', 'Item Type'], [
        ['UNK1', 'Mystery', 'PC', 'PC', 'SNACKZ'],
    ]);

    expect(SAPMasterfile::where('ItemCode', 'UNK1')->exists())->toBeTrue()
        ->and(SapItemType::count())->toBe($typesBefore)
        ->and(typeOf($entity->id, 'UNK1'))->toBeNull()
        ->and($import->getSkippedCount())->toBe(0)
        ->and($import->getWarnings())->toHaveCount(1)
        ->and($import->getWarnings()[0]['reason'])->toContain('not on the managed list');
});

it('keeps the first type when one file gives an item code two', function () {
    ['entity' => $entity, 'food' => $food] = sapTypeFixture();

    $import = runSapImport($entity->id, ['Item No.', 'Item Description', 'AltUom', 'BaseUom', 'Item Type'], [
        ['TWO1', 'Twice', 'PC', 'PC', 'FOOD'],
        ['TWO1', 'Twice', 'CASE(6)', 'PC', 'OPERATING SUPPLIES'],
    ]);

    expect(typeOf($entity->id, 'TWO1'))->toBe($food->id)
        ->and($import->getWarnings())->toHaveCount(1);
});

/** Builds the downloadable template and opens it the way Excel would. */
function sapTemplateWorkbook(): PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, Excel::raw(new App\Exports\SAPMasterfileTemplateExport, Maatwebsite\Excel\Excel::XLSX));

    return PhpOffice\PhpSpreadsheet\IOFactory::load($path);
}

it('gives the template one sheet with an Item Type dropdown and three samples', function () {
    sapTypeFixture();

    $book = sapTemplateWorkbook();
    $sheet = $book->getSheet(0);
    $validation = $sheet->getDataValidation('H2');

    expect($book->getSheetCount())->toBe(1)
        ->and($sheet->rangeToArray('A1:H1')[0])
        ->toBe(['Item No.', 'Item Description', 'AltQty', 'BaseQty', 'AltUom', 'BaseUom', 'Active', 'Item Type'])
        ->and(collect($sheet->rangeToArray('A2:A4'))->flatten()->all())->toBe(['SAMPLE-001', 'SAMPLE-001', 'SAMPLE-002'])
        ->and($sheet->getCell('A5')->getValue())->toBeNull()
        ->and($validation->getType())->toBe(PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
        // Active types only: the deactivated OLD TYPE is not offered.
        ->and($validation->getFormula1())->toBe('"FOOD,OPERATING SUPPLIES"');

    // One rule over the whole column, not just the sample rows. Read from the
    // file itself: PhpSpreadsheet's reader copies a range rule only onto cells
    // that hold values, so the loaded sheet would show H2-H4 alone.
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, Excel::raw(new App\Exports\SAPMasterfileTemplateExport, Maatwebsite\Excel\Excel::XLSX));
    $zip = new ZipArchive;
    $zip->open($path);
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('sqref="H2:H10001"');
});

it('reads a long type list from a hidden sheet, since Excel caps inline lists at 255 characters', function () {
    sapTypeFixture();
    foreach (range(1, 20) as $i) {
        SapItemType::create(['name' => sprintf('A VERY LONG ITEM TYPE NAME %02d', $i), 'is_active' => true]);
    }

    $book = sapTemplateWorkbook();

    expect($book->getSheet(0)->getDataValidation('H2')->getFormula1())->toStartWith('ItemTypes!')
        ->and($book->getSheetByName('ItemTypes')->getSheetState())
        ->toBe(PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);
});

it('imports nothing when the template is uploaded with its samples still in it', function () {
    ['entity' => $entity] = sapTypeFixture();
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, Excel::raw(new App\Exports\SAPMasterfileTemplateExport, Maatwebsite\Excel\Excel::XLSX));

    SAPMasterfileImport::resetSeenCombinations();
    $import = new SAPMasterfileImport($entity->id);
    Excel::import($import, $path);

    expect(SAPMasterfile::where('ItemCode', 'like', 'SAMPLE-%')->count())->toBe(0)
        ->and($import->getSkippedCount())->toBe(3)
        ->and(collect($import->getSkippedItems())->pluck('reason')->unique()->all())
        ->toBe(['Sample row from the template; not imported.']);
});

it('guards the types page with its own permission', function () {
    ['user' => $user] = sapTypeFixture(['view sapitems list']);

    $this->actingAs($user)->get(route('sap-item-types.index'))->assertForbidden();
});

it('opens the types page for someone who may manage types', function () {
    ['user' => $user] = sapTypeFixture();

    $this->actingAs($user)->get(route('sap-item-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SapItemType/Index'));

    expect(Route::has('sap-item-types.destroy'))->toBeFalse();
});

it('backfills from the month end templates once, skipping conflicts and unknown codes', function () {
    ['entity' => $entity, 'user' => $user] = sapTypeFixture();
    sapRow('MEC1', 'PC');
    sapRow('MEC2', 'PC');
    sapRow('CLASH', 'PC');
    $template = fn ($code, $category) => DB::table('month_end_count_templates')->insert([
        'item_code' => $code, 'item_name' => $code, 'category_2' => $category,
        'created_by' => $user->id, 'entity_id' => $entity->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $template('MEC1', 'FOOD');
    $template('MEC2', ' beverage ');
    $template('CLASH', 'FOOD');
    $template('CLASH', 'RETAIL');
    $template('GHOST', 'FOOD'); // not in sap_masterfiles

    $migration = require database_path('migrations/2026_09_21_000001_create_sap_item_types_and_backfill.php');
    $migration->up();
    $migration->up(); // re-running must not duplicate anything

    $assigned = DB::table('sap_item_type_assignments as a')
        ->join('sap_item_types as t', 't.id', '=', 'a.sap_item_type_id')
        ->where('a.entity_id', $entity->id)->orderBy('a.item_code')->pluck('t.name', 'a.item_code')->all();

    expect($assigned)->toBe(['MEC1' => 'FOOD', 'MEC2' => 'BEVERAGE'])
        ->and(DB::table('sap_item_types')->where('entity_id', $entity->id)->where('name', 'FOOD')->count())->toBe(1);
});
