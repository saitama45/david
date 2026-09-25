<?php

use App\Http\Controllers\POSMasterfileBOMController;
use App\Imports\POSMasterfileBOMImport;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Support\EntityContext;
use Illuminate\Http\Request;

function bomImportFixture(): Entity
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    POSMasterfile::create(['POSCode' => '30', 'POSDescription' => 'Lemon soda', 'SRP' => 100]);
    SAPMasterfile::create([
        'ItemCode' => 'RM-TC-0031', 'ItemDescription' => 'Fruit - Lemon',
        'AltUOM' => 'Gm', 'BaseUOM' => 'Gm', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
    ]);

    return $entity;
}

function bomRow(float $bomQty): \Illuminate\Support\Collection
{
    return collect([
        'pos_code' => '30', 'pos_description' => 'Lemon soda', 'assembly' => 'RM',
        'item_code' => 'RM-TC-0031', 'item_description' => 'Fruit - Lemon', 'rec_percent' => 1,
        'recipe_qty' => $bomQty, 'recipe_uom' => 'Gm', 'bom_qty' => $bomQty, 'bom_uom' => 'Gm',
        'unit_cost' => 0.2, 'total_cost' => $bomQty * 0.2,
    ]);
}

function runBomImport(array $rows): POSMasterfileBOMImport
{
    POSMasterfileBOMImport::resetSeenCombinations();
    $import = new POSMasterfileBOMImport;
    $import->collection(collect($rows));

    return $import;
}

function savedBomQtys(): array
{
    return POSMasterfileBOM::where('ItemCode', 'RM-TC-0031')->orderBy('BOMQty')->pluck('BOMQty')
        ->map(fn ($qty) => (float) $qty)->all();
}

it('holds back a repeated line with a different BOM Qty instead of overwriting the first', function () {
    bomImportFixture();

    $import = runBomImport([bomRow(43.48), bomRow(5)]);

    expect(savedBomQtys())->toBe([43.48])
        ->and($import->getProcessedCount())->toBe(1)
        ->and($import->getPendingDuplicates())->toHaveCount(1)
        ->and($import->getPendingDuplicates()[0]['row']['BOMQty'])->toBe('5.0000000')
        ->and($import->getPendingDuplicates()[0]['existing_qtys'])->toBe([43.48]);
});

it('adds allowed lines, and a re-upload of the same file updates them in place', function () {
    bomImportFixture();
    $pending = runBomImport([bomRow(43.48), bomRow(5)])->getPendingDuplicates();
    session()->put('pos_bom_pending_duplicates.'.app(EntityContext::class)->id(), $pending);

    app(POSMasterfileBOMController::class)->allowDuplicates(Request::create('/', 'POST', ['ids' => [$pending[0]['id']]]));

    expect(savedBomQtys())->toBe([5.0, 43.48])
        ->and(session('pos_bom_pending_duplicates.'.app(EntityContext::class)->id()))->toBe([]);

    $again = runBomImport([bomRow(43.48), bomRow(5)]);

    expect(savedBomQtys())->toBe([5.0, 43.48])
        ->and($again->getProcessedCount())->toBe(2)
        ->and($again->getPendingDuplicates())->toBe([]);
});

it('still corrects the BOM Qty of a single existing line on re-upload', function () {
    bomImportFixture();
    runBomImport([bomRow(43.48)]);

    $import = runBomImport([bomRow(40)]);

    expect(savedBomQtys())->toBe([40.0])
        ->and($import->getPendingDuplicates())->toBe([]);
});

it('dismisses held-back rows without saving them', function () {
    bomImportFixture();
    $pending = runBomImport([bomRow(43.48), bomRow(5)])->getPendingDuplicates();
    $key = 'pos_bom_pending_duplicates.'.app(EntityContext::class)->id();
    session()->put($key, $pending);

    app(POSMasterfileBOMController::class)->dismissDuplicates(Request::create('/', 'POST', ['ids' => [$pending[0]['id']]]));

    expect(savedBomQtys())->toBe([43.48])->and(session($key))->toBe([]);
});
