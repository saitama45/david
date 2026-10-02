<?php

use App\Http\Controllers\POSMasterfileBOMController;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-by-one create on the BOM List. Runs on an isolated test database.
 * Nothing here deletes a record.
 */
function bomCreateFixture(): array
{
    // Fresh migrations create a UNIQUE index on sap_masterfiles.ItemCode that the
    // live schema does not have (one row per AltUOM). Match the live schema.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();

    POSMasterfile::create(['POSCode' => 'POS-100', 'POSDescription' => 'Iced Latte', 'SRP' => 150, 'is_active' => true]);

    SAPMasterfile::create(['ItemCode' => 'RM-1', 'ItemDescription' => 'Fresh Milk', 'AltQty' => 1, 'AltUOM' => 'Lit', 'BaseQty' => 1, 'BaseUOM' => 'Lit', 'is_active' => true]);
    SAPMasterfile::create(['ItemCode' => 'RM-1', 'ItemDescription' => 'Fresh Milk', 'AltQty' => 1000, 'AltUOM' => 'Ml', 'BaseQty' => 1, 'BaseUOM' => 'Lit', 'is_active' => true]);

    return compact('entity', 'user');
}

function createBomLine(array $input)
{
    return app(POSMasterfileBOMController::class)->store(Request::create('/pos-bom-list/store', 'POST', array_merge([
        'POSCode' => 'POS-100', 'ItemCode' => 'RM-1', 'BOMQty' => 200, 'BOMUOM' => 'Ml',
    ], $input)));
}

function bomCreateErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('creates a BOM line with the masterlist descriptions and stamps the entity', function () {
    ['entity' => $entity, 'user' => $user] = bomCreateFixture();
    $this->actingAs($user);

    createBomLine(['BOMUOM' => 'ml', 'Assembly' => null]);

    $line = POSMasterfileBOM::where('POSCode', 'POS-100')->first();

    expect($line)->not->toBeNull()
        ->and($line->POSDescription)->toBe('Iced Latte')
        ->and($line->ItemDescription)->toBe('Fresh Milk')
        // Stored in SAP's spelling of the unit.
        ->and($line->BOMUOM)->toBe('Ml')
        ->and($line->Assembly)->toBe('')
        ->and((float) $line->BOMQty)->toBe(200.0)
        ->and((int) $line->entity_id)->toBe($entity->id)
        ->and((int) $line->created_by)->toBe($user->id);
});

it('refuses codes that are not on the masterlists, a unit the item does not have, and a zero BOM Qty', function () {
    ['user' => $user] = bomCreateFixture();
    $this->actingAs($user);

    expect(bomCreateErrors(fn () => createBomLine(['POSCode' => 'NOPE'])))->toHaveKey('POSCode')
        ->and(bomCreateErrors(fn () => createBomLine(['ItemCode' => 'NOPE'])))->toHaveKey('ItemCode')
        ->and(bomCreateErrors(fn () => createBomLine(['BOMUOM' => 'Case'])))->toHaveKey('BOMUOM')
        ->and(bomCreateErrors(fn () => createBomLine(['BOMQty' => 0])))->toHaveKey('BOMQty')
        ->and(POSMasterfileBOM::count())->toBe(0);
});

it('refuses the same line twice, and adds a repeat with another BOM Qty only when it is allowed', function () {
    ['user' => $user] = bomCreateFixture();
    $this->actingAs($user);

    createBomLine([]);

    // Same line, same BOM Qty: a duplicate.
    expect(bomCreateErrors(fn () => createBomLine([])))->toHaveKey('BOMQty');

    // Same line, another BOM Qty: held until the user confirms it.
    expect(bomCreateErrors(fn () => createBomLine(['BOMQty' => 50])))->toHaveKey('repeat')
        ->and(POSMasterfileBOM::count())->toBe(1);

    createBomLine(['BOMQty' => 50, 'allow_repeat' => true]);

    // Another unit or another Assembly is a different line and needs no confirmation.
    createBomLine(['BOMUOM' => 'Lit', 'BOMQty' => 0.2]);
    createBomLine(['Assembly' => 'Milk Foam']);

    expect(POSMasterfileBOM::count())->toBe(4);
});
