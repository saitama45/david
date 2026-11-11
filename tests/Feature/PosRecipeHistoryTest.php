<?php

use App\Http\Controllers\POSMasterfileBOMController;
use App\Http\Controllers\POSMasterfileController;
use App\Imports\POSMasterfileBOMImport;
use App\Imports\POSMasterfileImport;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * The BOM change history (pos_recipe_changes): every change to a BOM line or a POS item, from
 * the forms and the Excel imports alike, with who, when, old and new values and the source.
 * Runs on an isolated test database. The two deletes below remove a BOM line and a POS item of
 * this test's own fixture, inside RefreshDatabase's transaction; neither model soft-deletes.
 */
function historyFixture(): array
{
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);
    // Fixed emails: Changed By also matches the email, and a random one can contain any search term.
    $user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com']);
    test()->actingAs($user);

    POSMasterfile::create(['POSCode' => 'POS-LATTE', 'POSDescription' => 'Coffee Latte', 'SRP' => 150, 'is_active' => true]);
    SAPMasterfile::create(['ItemCode' => 'RM-MILK', 'ItemDescription' => 'Fresh Milk', 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'Ml', 'BaseUOM' => 'Ml', 'is_active' => true]);

    return compact('entity', 'user');
}

function historyBomRow(float $bomQty): \Illuminate\Support\Collection
{
    return collect([
        'pos_code' => 'POS-LATTE', 'pos_description' => 'Coffee Latte', 'assembly' => 'RM',
        'item_code' => 'RM-MILK', 'item_description' => 'Fresh Milk', 'rec_percent' => 1,
        'recipe_qty' => $bomQty, 'recipe_uom' => 'Ml', 'bom_qty' => $bomQty, 'bom_uom' => 'Ml',
        'unit_cost' => 0.1, 'total_cost' => $bomQty * 0.1,
    ]);
}

function historyImport(array $rows, string $file): POSMasterfileBOMImport
{
    POSMasterfileBOMImport::resetSeenCombinations();
    $import = new POSMasterfileBOMImport(null, $file);
    $import->collection(collect($rows));

    return $import;
}

function historyRows(): \Illuminate\Support\Collection
{
    return DB::table('pos_recipe_changes')->orderBy('id')->get()->map(function ($row) {
        $row->old = json_decode((string) $row->old_values, true);
        $row->new = json_decode((string) $row->new_values, true);

        return $row;
    });
}

it('records a BOM line an Excel import added and later changed, with the file, and nothing for the same file again', function () {
    ['entity' => $entity, 'user' => $user] = historyFixture();

    historyImport([historyBomRow(170)], 'bom-september.xlsx');
    historyImport([historyBomRow(170)], 'bom-september.xlsx'); // re-uploaded unchanged
    historyImport([historyBomRow(150)], 'bom-october.xlsx');

    $rows = historyRows();
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchObject([
            'subject' => 'bom_line', 'action' => 'created', 'source' => 'import', 'source_file' => 'bom-september.xlsx',
            'pos_code' => 'POS-LATTE', 'item_code' => 'RM-MILK', 'bom_uom' => 'Ml', 'assembly' => 'RM',
        ])
        ->and($rows[0]->new)->toMatchArray(['BOMQty' => '170', 'BOMUOM' => 'Ml', 'ItemDescription' => 'Fresh Milk'])
        ->and((int) $rows[0]->changed_by)->toBe($user->id)
        ->and((int) $rows[0]->entity_id)->toBe($entity->id)
        // Only the fields that changed: the quantity and the costs that follow it.
        ->and($rows[1])->toMatchObject(['action' => 'updated', 'source' => 'import', 'source_file' => 'bom-october.xlsx'])
        ->and($rows[1]->old)->toBe(['RecipeQty' => '170', 'BOMQty' => '170', 'TotalCost' => '17'])
        ->and($rows[1]->new)->toBe(['RecipeQty' => '150', 'BOMQty' => '150', 'TotalCost' => '15']);
});

it('records a repeat line allowed from the import review as its own source, with the file it came from', function () {
    historyFixture();
    historyImport([historyBomRow(170)], 'bom-september.xlsx');

    $data = ['POSCode' => 'POS-LATTE', 'ItemCode' => 'RM-MILK', 'BOMUOM' => 'Ml', 'Assembly' => 'RM', 'POSDescription' => 'Coffee Latte',
        'ItemDescription' => 'Fresh Milk', 'RecPercent' => '1.0000', 'RecipeQty' => '30.0000', 'RecipeUOM' => 'Ml',
        'BOMQty' => '30.0000000', 'UnitCost' => '0.1000', 'TotalCost' => '3.0000'];
    (new POSMasterfileBOMImport)->saveAllowedLine($data, 'bom-extra-foam.xlsx');

    expect(historyRows()->last())->toMatchObject(['action' => 'created', 'source' => 'allowed_repeat', 'source_file' => 'bom-extra-foam.xlsx'])
        ->and(POSMasterfileBOM::where('ItemCode', 'RM-MILK')->count())->toBe(2);
});

it('records the form create, edit and delete of a BOM line, keeping the deleted line\'s values', function () {
    ['user' => $user] = historyFixture();
    $controller = app(POSMasterfileBOMController::class);

    $controller->store(Request::create('/pos-bom-list/store', 'POST', ['POSCode' => 'POS-LATTE', 'ItemCode' => 'RM-MILK', 'BOMQty' => 170, 'BOMUOM' => 'Ml']));
    $line = POSMasterfileBOM::where('ItemCode', 'RM-MILK')->firstOrFail();

    $controller->update(Request::create('/pos-bom-list/update', 'PUT', [
        'POSCode' => 'POS-LATTE', 'POSDescription' => 'Coffee Latte', 'ItemCode' => 'RM-MILK', 'ItemDescription' => 'Fresh Milk',
        'Assembly' => '', 'RecPercent' => 0, 'RecipeQty' => 0, 'RecipeUOM' => '', 'BOMQty' => 150, 'BOMUOM' => 'Ml', 'UnitCost' => 0, 'TotalCost' => 0,
    ]), $line->fresh());

    $controller->destroy($line->fresh());

    $rows = historyRows();
    expect($rows->pluck('action')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($rows->pluck('source')->unique()->all())->toBe(['form'])
        ->and($rows->pluck('changed_by')->map(fn ($id) => (int) $id)->unique()->all())->toBe([$user->id])
        ->and($rows[1]->old)->toBe(['BOMQty' => '170'])
        ->and($rows[1]->new)->toBe(['BOMQty' => '150'])
        ->and($rows[2]->old)->toMatchArray(['POSCode' => 'POS-LATTE', 'ItemCode' => 'RM-MILK', 'BOMQty' => '150', 'BOMUOM' => 'Ml'])
        ->and($rows[2]->new)->toBeNull()
        ->and(POSMasterfileBOM::where('ItemCode', 'RM-MILK')->exists())->toBeFalse();
});

it('records POS items from the form and the import, including a POS Code change and a delete', function () {
    historyFixture();
    $controller = app(POSMasterfileController::class);

    $controller->store(Request::create('/POSMasterfile-list/store', 'POST', ['POSCode' => 'POS-MOCHA', 'POSDescription' => 'Mocha', 'SRP' => 160, 'is_active' => 1]));
    $mocha = POSMasterfile::where('POSCode', 'POS-MOCHA')->firstOrFail();
    $controller->update(Request::create('/POSMasterfile-list/update', 'PUT', ['POSCode' => 'POS-MOCHA-HOT', 'POSDescription' => 'Mocha']), (string) $mocha->id);

    POSMasterfileImport::resetSeenCombinations();
    (new POSMasterfileImport('pos-items.xlsx'))->collection(collect([collect(['pos_code' => 'POS-LATTE', 'pos_description' => 'Coffee Latte (Hot)', 'srp' => 150, 'active' => 1])]));

    $controller->destroy((string) $mocha->id);

    $rows = historyRows()->where('subject', 'pos_item')->values();
    expect($rows->pluck('action')->all())->toBe(['created', 'updated', 'updated', 'deleted'])
        ->and($rows[1]->old)->toBe(['POSCode' => 'POS-MOCHA'])
        ->and($rows[1]->new)->toBe(['POSCode' => 'POS-MOCHA-HOT'])
        ->and($rows[1]->pos_code)->toBe('POS-MOCHA-HOT')
        ->and($rows[2])->toMatchObject(['source' => 'import', 'source_file' => 'pos-items.xlsx', 'pos_code' => 'POS-LATTE'])
        ->and($rows[2]->old)->toMatchArray(['POSDescription' => 'Coffee Latte'])
        ->and($rows[2]->new)->toMatchArray(['POSDescription' => 'Coffee Latte (Hot)'])
        ->and($rows[3]->old)->toMatchArray(['POSCode' => 'POS-MOCHA-HOT', 'SRP' => '160']);
});

it('copies form changes from the audit log once, and none made after the history began', function () {
    ['user' => $user] = historyFixture();
    $line = POSMasterfileBOM::create(['POSCode' => 'POS-LATTE', 'POSDescription' => 'Coffee Latte', 'ItemCode' => 'RM-MILK', 'BOMQty' => 150, 'BOMUOM' => 'Ml']);
    $audit = fn (string $at, array $old, array $new) => DB::table('audits')->insertGetId([
        'user_type' => User::class, 'user_id' => $user->id, 'event' => 'updated',
        'auditable_type' => POSMasterfileBOM::class, 'auditable_id' => $line->id,
        'old_values' => json_encode($old), 'new_values' => json_encode($new), 'created_at' => $at, 'updated_at' => $at,
    ]);
    $before = $audit('2026-08-01 10:00:00', ['BOMQty' => '170.0000000'], ['BOMQty' => '150.0000000']);

    // The history's own first change, then an audit of the same moment onward: already in the history.
    \App\Support\PosRecipeHistory::bomLine('updated', ['id' => $line->id, 'POSCode' => 'POS-LATTE', 'Assembly' => 'RM'], ['id' => $line->id, 'POSCode' => 'POS-LATTE', 'Assembly' => 'Hot'], 'form', null, '2026-11-11 09:00:00');
    $audit('2026-11-11 09:30:00', ['Assembly' => 'RM'], ['Assembly' => 'Hot']);

    test()->artisan('bom:freeze-history', ['--apply' => true])->assertSuccessful();
    test()->artisan('bom:freeze-history', ['--apply' => true])->assertSuccessful();

    $copied = historyRows()->where('source', 'audit_log')->values();
    expect($copied)->toHaveCount(1)
        ->and($copied[0])->toMatchObject(['action' => 'updated', 'pos_code' => 'POS-LATTE', 'item_code' => 'RM-MILK'])
        ->and((int) $copied[0]->audit_id)->toBe($before)
        ->and(substr((string) $copied[0]->changed_at, 0, 19))->toBe('2026-08-01 10:00:00')
        ->and($copied[0]->old)->toBe(['BOMQty' => '170'])
        ->and($copied[0]->new)->toBe(['BOMQty' => '150']);
});

it('searches Changed By among the users who made a change, from three characters or an exact short name', function () {
    ['user' => $user] = historyFixture();
    $other = User::factory()->create(['first_name' => 'Al', 'last_name' => 'Cruz', 'email' => 'al.cruz@example.com']);
    User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Clara', 'email' => 'maria.clara@example.com']); // made no change: never offered
    historyImport([historyBomRow(170)], 'bom-september.xlsx');
    test()->actingAs($other);
    historyImport([historyBomRow(150)], 'bom-october.xlsx');

    $search = fn (string $q) => app(POSMasterfileBOMController::class)
        ->historyUsers(Request::create('/pos-bom-list/history/users', 'GET', ['q' => $q]), app(\App\Http\Services\PosRecipeHistoryService::class))
        ->getData(true);

    expect($search(''))->toBe([])
        ->and($search('Ma'))->toBe([])
        ->and(collect($search('mar'))->pluck('name')->all())->toBe(['Maria Santos'])
        // A name shorter than three characters is found when typed in full.
        ->and(collect($search('Al'))->pluck('name')->all())->toBe(['Al Cruz'])
        ->and(collect($search('cruz'))->pluck('name')->all())->toBe(['Al Cruz']);
});

it('shows the history to BOM List viewers', function () {
    ['user' => $user] = historyFixture();
    Permission::firstOrCreate(['name' => 'view POSMasterfile BOM list']);
    $user->givePermissionTo('view POSMasterfile BOM list');
    historyImport([historyBomRow(170)], 'bom-september.xlsx');

    // The page is new to the built Vite manifest until the next build.
    test()->withoutVite();
    test()->actingAs($user)->get(route('pos-bom.history', ['pos_code' => 'LATTE']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('POSMasterfileBOM/History')
            ->where('changes.total', 1)
            ->where('changes.data.0.action', 'Added')
            ->where('changes.data.0.source', 'Excel import')
            ->where('changes.data.0.source_file', 'bom-september.xlsx')
            ->where('changes.data.0.changed_by', 'Maria Santos')
            ->where('filters.pos_code', 'LATTE')
            ->where('filters.item_code', ''));
});

it('exports the filtered history with one row per field changed', function () {
    historyFixture();
    historyImport([historyBomRow(170)], 'bom-september.xlsx');
    historyImport([historyBomRow(150)], 'bom-october.xlsx');
    \Maatwebsite\Excel\Facades\Excel::fake();

    app(POSMasterfileBOMController::class)->historyExport(
        Request::create('/pos-bom-list/history/export', 'GET', ['action' => 'updated']),
        app(\App\Http\Services\PosRecipeHistoryService::class)
    );

    \Maatwebsite\Excel\Facades\Excel::assertDownloaded('bom_change_history-'.now()->format('Y-m-d').'.xlsx', function (\App\Exports\PosRecipeChangesExport $export) {
        $rows = $export->array();

        return count($rows) === 3
            && $rows[1] === [$rows[1][0], 'Maria Santos', 'BOM line', 'POS-LATTE', 'RM-MILK', 'Ml', 'RM', 'Changed', 'BOM Qty', '170', '150', 'Excel import', 'bom-october.xlsx'];
    });
});

it('refuses the history to a user who cannot view the BOM List', function () {
    ['user' => $user] = historyFixture();
    Permission::firstOrCreate(['name' => 'view POSMasterfile BOM list']);

    test()->actingAs($user)->get(route('pos-bom.history'))->assertForbidden();
});
