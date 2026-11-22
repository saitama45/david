<?php

use App\Http\Controllers\SupplierItemsController;
use App\Models\Entity;
use App\Models\SAPMasterfile;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The Supplier Items upload with a catalog laid out like TC's: a Sort Order typed as =M2+1,
 * and refusals the upload form has to show. Runs on an isolated test database; the controller
 * is called directly. Nothing here deletes a record.
 */
function supplierImportFixture(array $assign = ['TGI', 'DTS', 'GSI'], bool $bagBaseRow = true): Entity
{
    // Fresh migrations add a UNIQUE index on sap_masterfiles.ItemCode that the live schema
    // does not have (one row per AltUOM); 721A2H needs two rows.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'TC', 'code' => 'TC', 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    foreach (['TGI', 'DTS', 'GSI'] as $code) {
        Supplier::create(['supplier_code' => $code, 'name' => $code, 'is_active' => true]);
    }

    // TC's own SAP Masterlist states 1000 Gm = 1 Bag with no Bag / Bag row ($bagBaseRow false).
    $rows = [['721A2H', 'Bag', 1], ['721A2H', 'Gm', 1000], ['789A2H', 'Cylinder', 1], ['735A2H', 'Pack', 1]];
    foreach ($bagBaseRow ? $rows : array_slice($rows, 1) as [$code, $unit, $altQty]) {
        SAPMasterfile::create(['ItemCode' => $code, 'ItemDescription' => $code === '721A2H' ? 'TC ESPRESSO BLEND (1KG)' : $code,
            'AltQty' => $altQty, 'BaseQty' => 1, 'AltUOM' => $unit, 'BaseUOM' => $code === '721A2H' ? 'Bag' : $unit, 'is_active' => true]);
    }

    $user = User::factory()->create();
    foreach ($assign as $code) {
        $user->suppliers()->attach($code);
    }
    test()->actingAs($user);

    return $entity;
}

/**
 * A TC-style catalog. $savedResults false strips the result saved with each formula, as a file
 * written by a program (never opened in a spreadsheet) has none.
 */
function supplierImportWorkbook(bool $savedResults = true): UploadedFile
{
    $rows = [
        ['Category', 'Category 2', 'Area', 'Brand', 'Classification', 'Item Code', 'Item Name', 'Packaging Config', 'Unit', 'Cost', 'SRP', 'Supplier Code', 'Sort Order', 'ACTIVE'],
        ['INVENTORIABLE', 'BEVERAGE', 'CABINET', 'TC', 'BEANS & POWDER', '721A2H', 'TC ESPRESSO BLEND (1KG)', '1,000 Gm per Bag', 'Bag', 1381.38, null, 'TGI', 1, 1],
        ['INVENTORIABLE', 'BEVERAGE', 'CABINET', 'TC', 'BEANS & POWDER', '721A2H', 'TC ESPRESSO BLEND (1KG)', '1,000 Gm per Bag', 'Gm', 1.38, null, 'TGI', '=M2+1', 1],
        ['INVENTORIABLE', 'OPERATING SUPPLIES', 'CABINET', 'TC', 'GAS', '789A2H', 'TC - Sodarizer C02 refill', '1 Cylinder', 'Cylinder', 850, null, 'DTS', '=M3+1', 1],
        ['INVENTORIABLE', 'FOOD', 'CABINET', 'TC', 'PASTRY', '735A2H', 'Frozen Liège Waffle with Pearl Sugar', '6 Pc per Pack', 'Pack', 304, null, 'GSI', '=M4+1', 1],
    ];

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);

    $path = tempnam(sys_get_temp_dir(), 'supplier-items').'.xlsx';
    (new Xlsx($spreadsheet))->save($path); // saves each formula's result, as Excel does

    if (! $savedResults) {
        $zip = new ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', preg_replace('#(</f>)<v>[^<]*</v>#', '$1', $sheet));
        $zip->close();
    }

    return new UploadedFile($path, 'SupplierItems_template_TC.xlsx', null, null, true);
}

function supplierImportUpload(UploadedFile $file)
{
    return app(SupplierItemsController::class)->import(
        Request::create('/SupplierItems-list/import', 'POST', [], [], ['products_file' => $file])
    );
}

function supplierImportErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function supplierImportRows(): array
{
    return SupplierItems::orderBy('id')->get()
        ->map(fn ($item) => [$item->ItemCode, $item->uom, $item->SupplierCode, (int) $item->sort_order])
        ->all();
}

it('imports a catalog into the active entity, with the Sort Order its formulas show', function () {
    $entity = supplierImportFixture();

    $response = supplierImportUpload(supplierImportWorkbook());

    expect($response->getTargetUrl())->toBe(route('SupplierItems.index'))
        ->and(session('import_summary.processed_count'))->toBe(4)
        ->and(supplierImportRows())->toEqualCanonicalizing([
            ['721A2H', 'Bag', 'TGI', 1],
            ['721A2H', 'Gm', 'TGI', 2],
            ['789A2H', 'Cylinder', 'DTS', 3],
            ['735A2H', 'Pack', 'GSI', 4],
        ])
        ->and(SupplierItems::pluck('entity_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->toBe([$entity->id])
        ->and(SupplierItems::where('ItemCode', '735A2H')->value('item_name'))->toBe('Frozen Liège Waffle with Pearl Sugar');
});

it('calculates a Sort Order formula that was saved without its result', function () {
    supplierImportFixture();

    supplierImportUpload(supplierImportWorkbook(savedResults: false));

    expect(collect(supplierImportRows())->pluck(3)->sort()->values()->all())->toBe([1, 2, 3, 4]);
});

it('tells a user with no supplier in the entity why nothing was imported, on the upload form', function () {
    supplierImportFixture(assign: []);

    $errors = supplierImportErrors(fn () => supplierImportUpload(supplierImportWorkbook()));

    expect($errors['products_file'][0] ?? '')->toContain('You have no suppliers assigned in TC, so nothing was imported.')
        ->and(SupplierItems::count())->toBe(0);
});

it('reports a failed import on the upload form', function () {
    supplierImportFixture();
    app(EntityContext::class)->clear(); // the import refuses to write rows without an entity

    $errors = supplierImportErrors(fn () => supplierImportUpload(supplierImportWorkbook()));

    expect($errors['products_file'][0] ?? '')->toStartWith('Import failed: No active entity')
        ->and(SupplierItems::withoutGlobalScopes()->count())->toBe(0);
});

it('imports a unit SAP gives only as the BaseUOM, as TC\'s 1000 Gm = 1 Bag with no Bag / Bag row', function () {
    supplierImportFixture(bagBaseRow: false);

    supplierImportUpload(supplierImportWorkbook());

    expect(session('import_summary.processed_count'))->toBe(4)
        ->and(collect(supplierImportRows())->map(fn ($row) => $row[0].' '.$row[1])->all())
        ->toContain('721A2H Bag', '721A2H Gm');
});

it('adds an item one by one in a unit SAP gives only as the BaseUOM, and still refuses a unit SAP does not give', function () {
    supplierImportFixture(bagBaseRow: false);
    $store = fn (string $unit) => app(SupplierItemsController::class)->store(Request::create('/SupplierItems-list', 'POST', [
        'SupplierCode' => 'TGI', 'ItemCode' => '721A2H', 'uom' => $unit, 'cost' => 1381.38, 'is_active' => true,
    ]));

    $store('bag');

    expect(SupplierItems::where('ItemCode', '721A2H')->get()->map(fn ($item) => [$item->uom, $item->item_name])->all())
        ->toBe([['Bag', 'TC ESPRESSO BLEND (1KG)']])
        ->and(supplierImportErrors(fn () => $store('Case'))['uom'][0] ?? '')
        ->toBe("'Case' is not a unit of 721A2H in the SAP Masterfile. Use one of: Gm, Bag.");
});

it('finds the SAP row of a supplier item in a unit SAP gives only as the BaseUOM, for its name on order lists', function () {
    $entity = supplierImportFixture(bagBaseRow: false);
    supplierImportUpload(supplierImportWorkbook());

    $bag = SupplierItems::where('ItemCode', '721A2H')->where('uom', 'Bag')->first();
    $joined = DB::query()->fromSub(SAPMasterfile::singleRowPerJoinKey($entity->id), 'sm')
        ->where('ItemCode', '721A2H')->orderBy('AltUOM')->get(['AltUOM', 'BaseUOM', 'ItemDescription']);

    expect($bag->sap_masterfile?->BaseUOM)->toBe('Bag')
        ->and($bag->load('sapMasterfiles')->sap_masterfile?->BaseUOM)->toBe('Bag')
        // One row per unit: Bag stands in as 1 Bag = 1 Bag, Gm keeps its own row.
        ->and($joined->map(fn ($row) => [$row->AltUOM, $row->BaseUOM, $row->ItemDescription])->all())->toBe([
            ['Bag', 'Bag', 'TC ESPRESSO BLEND (1KG)'],
            ['Gm', 'Bag', 'TC ESPRESSO BLEND (1KG)'],
        ]);
});
