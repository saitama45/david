<?php

use App\Http\Services\RoleService;
use App\Http\Services\SohAdjustmentService;
use App\Imports\UpdateStockManagementSOH;
use App\Models\Entity;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * SOH Adjustment: the page lists a store's items with their stock on hand, a correction is
 * filed there and waits, and only its approval changes the stock.
 *
 * The service is called directly where the saved rows are read back: every test HTTP
 * request disconnects the database on terminate(), which rolls back RefreshDatabase.
 * Nothing here deletes a record.
 */
function sohFixture(): array
{
    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create(['first_name' => 'Juan', 'last_name' => 'Cruz']);
    $store = StoreBranch::create([
        'branch_code' => 'TSOH', 'brand_code' => 'TSOH', 'name' => 'SOH Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);
    $user->store_branches()->attach($store->id);

    // Cocoa is kept by the Bag (1,000 Gm) and can also be counted in grams.
    $rows = [];
    foreach ([['Gm', 'Bag', 1000, 1], ['Bag', 'Bag', 1, 1]] as [$alt, $base, $altQty, $baseQty]) {
        $rows[$alt] = SAPMasterfile::create([
            'ItemCode' => 'RM-COCOA', 'ItemDescription' => 'Powder - Cocoa',
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ]);
    }

    // 2 Bag on hand. Through the model, so the row carries its entity as real stock history does.
    ProductInventoryStockManager::create([
        'product_inventory_id' => $rows['Bag']->id, 'store_branch_id' => $store->id, 'quantity' => 2, 'action' => 'add',
        'unit_cost' => 0, 'total_cost' => 0, 'transaction_date' => '2026-10-01',
    ]);

    return compact('entity', 'user', 'store', 'rows');
}

function sohBalance(array $f): float
{
    return app(SohAdjustmentService::class)->balances($f['store']->id, [$f['rows']['Bag']->id])->get($f['rows']['Bag']->id) ?? 0.0;
}

function sohErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('files a new SOH as a difference in the stock unit that changes no stock yet', function () {
    $f = sohFixture();

    // Counted 2,500 Gm where the books say 2 Bag (2,000 Gm).
    $adjustment = app(SohAdjustmentService::class)->requestNewQuantity($f['rows']['Gm'], $f['store']->id, 2500, 'Recount after delivery', $f['user']);

    expect((int) $adjustment->product_inventory_id)->toBe($f['rows']['Bag']->id)
        ->and($adjustment->action)->toBe('soh_adjustment')
        ->and(round((float) $adjustment->quantity, 4))->toBe(0.5)
        ->and($adjustment->fresh()->is_stock_adjustment_approved)->toBeFalse()
        ->and($adjustment->remarks)->toBe('SOH Adjustment: Recount after delivery | new SOH 2500 Gm, was 2000 Gm | requested by '.$f['user']->name)
        ->and(sohBalance($f))->toBe(2.0);
});

it('refuses a new SOH equal to the current one, and a second adjustment while one waits', function () {
    $f = sohFixture();
    $service = app(SohAdjustmentService::class);

    expect(sohErrors(fn () => $service->requestNewQuantity($f['rows']['Bag'], $f['store']->id, 2, 'Same', $f['user'])))
        ->toBe(['new_quantity' => ['The new SOH is the same as the current SOH.']]);

    $service->requestNewQuantity($f['rows']['Bag'], $f['store']->id, 3, 'First', $f['user']);

    // In another unit of the same item too: both would be measured from the same SOH.
    expect(sohErrors(fn () => $service->requestNewQuantity($f['rows']['Gm'], $f['store']->id, 500, 'Second', $f['user'])))
        ->toBe(['new_quantity' => ['This item already has an adjustment waiting for approval at this store.']])
        ->and($service->pending($f['store']->id))->toHaveCount(1);
});

it('adds the stock when an upward adjustment is approved, once', function () {
    $f = sohFixture();
    $service = app(SohAdjustmentService::class);
    $adjustment = $service->requestNewQuantity($f['rows']['Gm'], $f['store']->id, 2500, 'Recount', $f['user']);

    $service->approve($adjustment, $f['user']);
    $row = $adjustment->fresh();

    expect($row->action)->toBe('add')
        ->and(round((float) $row->quantity, 4))->toBe(0.5)
        ->and($row->is_stock_adjustment_approved)->toBeTrue()
        ->and($row->remarks)->toEndWith('| approved by '.$f['user']->name)
        ->and(sohBalance($f))->toBe(2.5)
        // The store's cached balance follows the stock history.
        ->and((float) ProductInventoryStock::where('product_inventory_id', $f['rows']['Bag']->id)->where('store_branch_id', $f['store']->id)->value('quantity'))->toBe(2.5)
        ->and($service->pending($f['store']->id))->toHaveCount(0)
        ->and(sohErrors(fn () => $service->approve($adjustment, $f['user'])))->toHaveKey('selectedItems')
        ->and(sohBalance($f))->toBe(2.5);
});

it('deducts the stock when a downward adjustment is approved', function () {
    $f = sohFixture();
    $service = app(SohAdjustmentService::class);
    $adjustment = $service->requestNewQuantity($f['rows']['Bag'], $f['store']->id, 0.75, 'Spilled bag', $f['user']);

    expect(round((float) $adjustment->quantity, 4))->toBe(-1.25);

    $service->approve($adjustment, $f['user']);

    expect($adjustment->fresh()->action)->toBe('out')
        ->and(round((float) $adjustment->fresh()->quantity, 4))->toBe(1.25)
        ->and(sohBalance($f))->toBe(0.75);
});

it('keeps a rejected adjustment on file without changing the stock, and frees the item', function () {
    $f = sohFixture();
    $service = app(SohAdjustmentService::class);
    $adjustment = $service->requestNewQuantity($f['rows']['Bag'], $f['store']->id, 9, 'Typo', $f['user']);

    $service->reject($adjustment, $f['user']);

    expect($adjustment->fresh()->action)->toBe('soh_adjustment_rejected')
        ->and($adjustment->fresh()->remarks)->toEndWith('| rejected by '.$f['user']->name)
        ->and(ProductInventoryStockManager::whereKey($adjustment->id)->exists())->toBeTrue()
        ->and(sohBalance($f))->toBe(2.0)
        ->and(sohErrors(fn () => $service->approve($adjustment, $f['user'])))->toHaveKey('selectedItems');

    $service->requestNewQuantity($f['rows']['Bag'], $f['store']->id, 3, 'Corrected', $f['user']);

    expect($service->pending($f['store']->id))->toHaveCount(1);
});

it('lists the store items with their SOH per unit, and the adjustment waiting on them', function () {
    $f = sohFixture();
    Permission::findOrCreate('view soh adjustment');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view soh adjustment');
    app(SohAdjustmentService::class)->requestNewQuantity($f['rows']['Gm'], $f['store']->id, 2500, 'Recount', $f['user']);

    $this->actingAs($f['user'])->get(route('soh-adjustment.index', ['branchId' => $f['store']->id, 'search' => 'cocoa']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SOHAdjustment/Index')
            ->where('tab', 'items')
            ->where('pendingCount', 1)
            ->where('items.data', [
                ['id' => $f['rows']['Bag']->id, 'item_code' => 'RM-COCOA', 'name' => 'Powder - Cocoa', 'uom' => 'Bag', 'is_active' => true, 'adjustable' => true, 'soh' => 2, 'pending_difference' => 0.5],
                ['id' => $f['rows']['Gm']->id, 'item_code' => 'RM-COCOA', 'name' => 'Powder - Cocoa', 'uom' => 'Gm', 'is_active' => true, 'adjustable' => true, 'soh' => 2000, 'pending_difference' => 500],
            ]));
});

it('lists what waits for approval with the SOH it would give', function () {
    $f = sohFixture();
    Permission::findOrCreate('view soh adjustment');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view soh adjustment');
    $adjustment = app(SohAdjustmentService::class)->requestNewQuantity($f['rows']['Gm'], $f['store']->id, 2500, 'Recount', $f['user']);

    $this->actingAs($f['user'])->get(route('soh-adjustment.index', ['branchId' => $f['store']->id, 'tab' => 'pending']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'pending')
            ->where('items', null)
            ->has('pending', 1)
            ->where('pending.0.id', $adjustment->id)
            ->where('pending.0.item_code', 'RM-COCOA')
            ->where('pending.0.uom', 'Bag')
            ->where('pending.0.soh', 2)
            ->where('pending.0.difference', 0.5)
            ->where('pending.0.new_soh', 2.5));
});

it('turns an uploaded variance into a waiting adjustment, and refuses an ID that is not the item beside it', function () {
    $f = sohFixture();
    $this->actingAs($f['user']);

    $import = new UpdateStockManagementSOH($f['store']->id);
    $import->collection(collect([
        collect(['id' => $f['rows']['Gm']->id, 'item_code' => 'RM-COCOA', 'variance' => -250, 'remarks' => 'Monthly recount']),
        collect(['id' => $f['rows']['Bag']->id, 'item_code' => 'RM-COCOA', 'variance' => 0, 'remarks' => null]),
        // A file from before the SAP Masterlist: the id belongs to another table.
        collect(['id' => $f['rows']['Bag']->id, 'inventory_code' => 'OLD-0001', 'variance' => 3, 'remarks' => null]),
    ]));

    $pending = app(SohAdjustmentService::class)->pending($f['store']->id);

    expect($pending)->toHaveCount(1)
        ->and(round((float) $pending->first()->quantity, 4))->toBe(-0.25)
        ->and($pending->first()->remarks)->toBe('SOH Adjustment: Monthly recount | variance -250 Gm | requested by '.$f['user']->name)
        ->and($import->getErrors())->toBe(['Row 4: The ID and Item Code are not an item of the SAP Masterlist. Download the SOH Update file again.'])
        ->and(sohBalance($f))->toBe(2.0);
});

function sohPermit(User $user, string ...$permissions): void
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo($permissions);
}

it('sends an adjustment for approval from the page', function () {
    $f = sohFixture();
    sohPermit($f['user'], 'view soh adjustment', 'create soh adjustment');

    $this->actingAs($f['user'])->from(route('soh-adjustment.index'))
        ->post(route('soh-adjustment.store'), [
            'branchId' => $f['store']->id, 'sap_masterfile_id' => $f['rows']['Gm']->id, 'new_quantity' => 2500, 'remarks' => 'Recount',
        ])
        ->assertRedirect(route('soh-adjustment.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'SOH adjustment sent for approval.');
});

it('asks for a remark and a new SOH that is not below zero', function () {
    $f = sohFixture();
    sohPermit($f['user'], 'view soh adjustment', 'create soh adjustment');

    $this->actingAs($f['user'])->from(route('soh-adjustment.index'))
        ->post(route('soh-adjustment.store'), [
            'branchId' => $f['store']->id, 'sap_masterfile_id' => $f['rows']['Gm']->id, 'new_quantity' => -1, 'remarks' => '',
        ])
        ->assertSessionHasErrors(['new_quantity', 'remarks']);
});

it('refuses an adjustment for a store the user is not assigned to', function () {
    $f = sohFixture();
    sohPermit($f['user'], 'view soh adjustment', 'create soh adjustment');
    $other = StoreBranch::create([
        'branch_code' => 'TOTH', 'brand_code' => 'TOTH', 'name' => 'Other Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);

    $this->actingAs($f['user'])
        ->post(route('soh-adjustment.store'), [
            'branchId' => $other->id, 'sap_masterfile_id' => $f['rows']['Gm']->id, 'new_quantity' => 5, 'remarks' => 'Recount',
        ])
        ->assertForbidden();
});

it('approves from the page only with the approve permission', function (array $permissions, int $status) {
    $f = sohFixture();
    sohPermit($f['user'], ...$permissions);
    Permission::findOrCreate('approve soh adjustment');
    $adjustment = app(SohAdjustmentService::class)->requestNewQuantity($f['rows']['Gm'], $f['store']->id, 2500, 'Recount', $f['user']);

    $this->actingAs($f['user'])->from(route('soh-adjustment.index'))
        ->post(route('soh-adjustment.approve-selected-items'), ['selectedItems' => [$adjustment->id], 'branchId' => $f['store']->id])
        ->assertStatus($status);
})->with([
    'approver' => [['view soh adjustment', 'approve soh adjustment'], 302],
    'creator only' => [['view soh adjustment', 'create soh adjustment'], 403],
]);

it('offers the approve permission in the role editor', function () {
    Permission::findOrCreate('approve soh adjustment');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(json_encode(app(RoleService::class)->getPermissionsGroup()))->toContain('approve soh adjustment');
});
