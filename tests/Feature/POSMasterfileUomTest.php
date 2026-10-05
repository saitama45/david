<?php

use App\Http\Controllers\POSMasterfileController;
use App\Imports\POSMasterfileImport;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The POS Masterlist UOM field, and the Category the Edit page shows.
 *
 * Controllers are called directly where the saved rows are read back: every test HTTP
 * request disconnects the database on terminate(), which rolls back RefreshDatabase.
 */
function posUomFixture(): Entity
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    return $entity;
}

function posUomRow(array $extra = []): \Illuminate\Support\Collection
{
    return collect(array_merge([
        'product_id' => 'SP-0001', 'pos_desc' => 'Chocolate Mix', 'category' => 'Sub-Prep',
        'subcategory' => 'Sub-Prep', 'srp' => 0, 'active' => 1,
    ], $extra));
}

function posUomImport(array $rows): void
{
    POSMasterfileImport::resetSeenCombinations();
    (new POSMasterfileImport)->collection(collect($rows));
}

it('saves the UOM typed on the create form', function () {
    posUomFixture();

    app(POSMasterfileController::class)->store(Request::create('/POSMasterfile-list/store', 'POST', [
        'POSCode' => 'SP-0001', 'POSDescription' => 'Chocolate Mix', 'Category' => 'Sub-Prep',
        'UOM' => ' Gm ', 'SRP' => 0, 'is_active' => 1,
    ]));

    expect(POSMasterfile::where('POSCode', 'SP-0001')->sole()->UOM)->toBe('Gm');
});

it('saves the UOM and the category typed on the edit form, and refuses a UOM over 50 characters', function () {
    posUomFixture();
    $item = POSMasterfile::create(['POSCode' => 'SP-0001', 'POSDescription' => 'Chocolate Mix', 'Category' => 'Sub-Prep', 'SRP' => 0]);
    $update = fn (array $input) => app(POSMasterfileController::class)->update(Request::create('/POSMasterfile-list/update/'.$item->id, 'PUT', array_merge([
        'POSCode' => 'SP-0001', 'POSDescription' => 'Chocolate Mix', 'Category' => 'Sub-Prep',
        'SubCategory' => null, 'SRP' => 0, 'is_active' => 1,
    ], $input)), (string) $item->id);

    $update(['UOM' => 'Gm', 'Category' => 'Sauces']);

    expect($item->fresh()->only(['UOM', 'Category']))->toBe(['UOM' => 'Gm', 'Category' => 'Sauces']);

    $errors = [];
    try {
        $update(['UOM' => str_repeat('x', 51)]);
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect($errors)->toHaveKey('UOM')
        ->and($item->fresh()->UOM)->toBe('Gm');
});

it('offers the item its own category on the edit page when no menu category has that name', function () {
    posUomFixture();
    Permission::findOrCreate('edit POSMasterfile');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::factory()->create();
    $user->givePermissionTo('edit POSMasterfile');

    $item = POSMasterfile::create(['POSCode' => 'SP-0001', 'POSDescription' => 'Chocolate Mix', 'Category' => 'Sub-Prep', 'UOM' => 'Gm', 'SRP' => 0]);
    POSMasterfile::create(['POSCode' => 'FG-0001', 'POSDescription' => 'Latte', 'Category' => 'Drip Coffee', 'SRP' => 150]);

    $this->actingAs($user)->get(route('POSMasterfile.edit', $item->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('POSMasterfile/Edit')
            ->where('item.Category', 'Sub-Prep')
            ->where('item.UOM', 'Gm')
            ->where('categories', ['Drip Coffee', 'Sub-Prep']));
});

it('imports the UOM column, and leaves the UOM alone when the file has no such column', function () {
    posUomFixture();

    posUomImport([posUomRow(['uom' => ' Gm '])]);
    expect(POSMasterfile::where('POSCode', 'SP-0001')->sole()->UOM)->toBe('Gm');

    // The template before the UOM column existed.
    posUomImport([posUomRow(['pos_desc' => 'Chocolate Mix v2'])]);
    expect(POSMasterfile::where('POSCode', 'SP-0001')->sole()->only(['POSDescription', 'UOM']))
        ->toBe(['POSDescription' => 'Chocolate Mix v2', 'UOM' => 'Gm']);

    // The column is there but the cell is blank: the file says the item has no UOM.
    posUomImport([posUomRow(['uom' => null])]);
    expect(POSMasterfile::where('POSCode', 'SP-0001')->sole()->UOM)->toBeNull();
});
