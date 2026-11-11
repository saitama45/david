<?php

use App\Http\Controllers\InventoryMovementReportController;
use App\Http\Services\InventoryMovementDetailService;
use App\Http\Services\InventoryMovementService;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Services\StoreTransactionReceiptProcessor;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * A sale keeps the ingredients it used: they are frozen into bom_consumptions when it is
 * posted, and the Inventory Movement Report reads them instead of today's BOM. A sale posted
 * before that reads the BOM until bom:freeze-history freezes it. Runs on an isolated test
 * database; services and controllers are called directly (a test HTTP request disconnects the
 * database and rolls RefreshDatabase back). Nothing here deletes a record.
 */
function frozenFixture(float $bomQty = 170): array
{
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    Permission::firstOrCreate(['name' => 'view inventory movement report']);
    $user = User::factory()->create();
    $user->givePermissionTo('view inventory movement report');

    $branch = StoreBranch::create(['branch_code' => 'NNFRZ', 'brand_code' => 'NNFRZ', 'name' => 'Frozen Store', 'store_status' => 'Active', 'is_active' => 1]);
    $user->store_branches()->attach($branch->id);

    SAPMasterfile::create(['ItemCode' => 'RM-MILK', 'ItemDescription' => 'Fresh Milk', 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'Ml', 'BaseUOM' => 'Ml', 'is_active' => true]);
    SAPMasterfile::create(['ItemCode' => 'RM-OAT', 'ItemDescription' => 'Oat Milk', 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'Ml', 'BaseUOM' => 'Ml', 'is_active' => true]);
    $pos = POSMasterfile::create(['POSCode' => 'POS-LATTE', 'POSDescription' => 'Coffee Latte', 'SRP' => 150, 'is_active' => true]);
    $bom = POSMasterfileBOM::create([
        'POSCode' => 'POS-LATTE', 'POSDescription' => 'Coffee Latte', 'ItemCode' => 'RM-MILK', 'ItemDescription' => 'Fresh Milk',
        'BOMQty' => $bomQty, 'BOMUOM' => 'Ml', 'UnitCost' => 0.1,
    ]);

    return compact('entity', 'user', 'branch', 'pos', 'bom');
}

function frozenPostSale(array $f, string $receipt, int $cups, string $date = '2026-09-16'): void
{
    $processor = new StoreTransactionReceiptProcessor(null, 'pos');
    $sale = $processor->processReceiptGroup(collect([[
        '__row_number' => 1, '__branch_id' => $f['branch']->id, 'branch' => 'NNFRZ', 'receipt_no' => $receipt,
        'date' => $date, 'posted' => '1200', 'tm' => '1', 'product_id' => 'POS-LATTE', 'product_name' => 'POS-LATTE',
        'uom' => 'Cup', 'qty' => $cups, 'base_qty' => $cups, 'price' => 150, 'discount' => 0,
        'line_total' => 150 * $cups, 'net_total' => 150 * $cups, 'take_out' => '',
    ]]));

    expect($processor->getSkippedRows())->toBe([])->and($sale)->not->toBeNull();
}

/** A sale as the older imports left it: items, no frozen lines. */
function frozenLegacySale(array $f, string $receipt, int $cups, string $date = '2026-09-16'): int
{
    $id = DB::table('store_transactions')->insertGetId([
        'entity_id' => $f['entity']->id, 'store_branch_id' => $f['branch']->id, 'order_date' => $date, 'posted' => 'Y',
        'tim_number' => '1', 'receipt_number' => $receipt, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('store_transaction_items')->insert([
        'store_transaction_id' => $id, 'product_id' => $f['pos']->id, 'base_quantity' => $cups, 'quantity' => $cups,
        'price' => 150, 'discount' => 0, 'line_total' => 150 * $cups, 'net_total' => 150 * $cups, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function frozenSalesQty(array $f, string $itemCode = 'RM-MILK'): ?float
{
    $row = collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::where('ItemCode', $itemCode)->get(),
        ['branch_id' => $f['branch']->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']
    ))->firstWhere('sap_code', $itemCode);

    return $row === null ? null : (float) $row['sales_qty'];
}

function frozenSalesPopup(array $f): array
{
    return app(InventoryMovementDetailService::class)->details(
        SAPMasterfile::where('ItemCode', 'RM-MILK')->get(), $f['branch']->id,
        ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], 'sales', 1, null
    );
}

it('keeps a posted sale at the recipe it was posted with when the BOM changes afterwards', function () {
    $f = frozenFixture(170);
    frozenPostSale($f, '1001', 2); // 2 lattes x 170 Ml

    expect(frozenSalesQty($f))->toBe(340.0);

    // The milk in a latte is cut to 150 Ml today: the September sale still used 170 Ml each.
    $f['bom']->update(['BOMQty' => 150]);

    $popup = frozenSalesPopup($f);
    expect(frozenSalesQty($f))->toBe(340.0)
        ->and($popup['total'])->toBe(340.0)
        ->and($popup['rows'][0])->toMatchArray(['ref_no' => '1001', 'quantity' => 340.0]);

    // A sale after the change is at the new recipe; the two add up.
    frozenPostSale($f, '1002', 1);
    expect(frozenSalesQty($f))->toBe(490.0)
        ->and(frozenSalesPopup($f)['total'])->toBe(490.0);
});

it('reads the BOM for a sale not frozen yet, until bom:freeze-history freezes it, and runs once', function () {
    $f = frozenFixture(170);
    $sale = frozenLegacySale($f, 'OLD-1', 2);

    expect(frozenSalesQty($f))->toBe(340.0);

    // The dry run writes nothing.
    test()->artisan('bom:freeze-history')->assertSuccessful();
    expect(DB::table('bom_consumptions')->count())->toBe(0)
        ->and(DB::table('store_transactions')->where('id', $sale)->value('bom_frozen_at'))->toBeNull();

    test()->artisan('bom:freeze-history', ['--apply' => true])->assertSuccessful();
    $line = DB::table('bom_consumptions')->where('store_transaction_id', $sale)->first();
    expect($line->origin)->toBe('current_bom')
        ->and((float) $line->qty)->toBe(340.0)
        ->and(DB::table('store_transactions')->where('id', $sale)->value('bom_frozen_at'))->not->toBeNull();

    // Frozen: the recipe change no longer reaches it, and a second run adds nothing.
    $f['bom']->update(['BOMQty' => 150]);
    test()->artisan('bom:freeze-history', ['--apply' => true])->assertSuccessful();
    expect(frozenSalesQty($f))->toBe(340.0)
        ->and(DB::table('bom_consumptions')->count())->toBe(1);
});

it('reads the recipes and units of a whole batch in one query each and writes its lines in one statement', function () {
    $f = frozenFixture(170);
    foreach (range(1, 5) as $i) {
        frozenLegacySale($f, "OLD-{$i}", $i);
    }
    $queries = collect();
    DB::listen(fn ($query) => $queries->push($query->sql));

    test()->artisan('bom:freeze-history', ['--apply' => true])->assertSuccessful();

    // A distant database costs a round trip per query: per batch, not per sale or per recipe.
    expect($queries->filter(fn ($sql) => str_contains($sql, '[pos_masterfiles_bom]'))->count())->toBe(1)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'from [sap_masterfiles]'))->count())->toBe(1)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'OPENJSON'))->count())->toBe(1)
        ->and(DB::table('bom_consumptions')->count())->toBe(5)
        ->and((float) DB::table('bom_consumptions')->sum('qty'))->toBe(2550.0) // (1+2+3+4+5) lattes x 170 Ml
        ->and(DB::table('store_transactions')->whereNull('bom_frozen_at')->count())->toBe(0)
        // Written in Manila wall time, as Eloquent writes timestamps.
        ->and(abs(\Carbon\Carbon::parse(DB::table('bom_consumptions')->value('created_at'))->diffInMinutes(now())))->toBeLessThan(5);
});

it('freezes a sale posted before lines were kept at what its posting really deducted, not at today\'s BOM', function () {
    $f = frozenFixture(170);
    frozenPostSale($f, '1001', 2);
    // As if posted before this release: its posting record and stock movements, but no live lines.
    DB::table('bom_consumptions')->update(['superseded_at' => now()]);
    DB::table('store_transactions')->update(['bom_frozen_at' => null]);

    $f['bom']->update(['BOMQty' => 150]);
    // Not frozen yet, it follows today's recipe: the problem this release fixes.
    expect(frozenSalesQty($f))->toBe(300.0);

    test()->artisan('bom:freeze-history', ['--apply' => true])->assertSuccessful();

    $line = DB::table('bom_consumptions')->whereNull('superseded_at')->first();
    expect($line->origin)->toBe('snapshot')
        ->and($line->bom_uom)->toBe('Ml')
        ->and((float) $line->bom_qty)->toBe(170.0)
        ->and((float) $line->units)->toBe(2.0)
        ->and(frozenSalesQty($f))->toBe(340.0);
});

it('freezes a non-inventory sale with no lines, so a BOM added to it later does not reach back', function () {
    $f = frozenFixture(170);
    $gift = POSMasterfile::create(['POSCode' => 'POS-GIFT', 'POSDescription' => 'Gift Card', 'SRP' => 500, 'is_active' => true]);
    config(['sales_posting.non_inventory_products.'.$f['entity']->id => ['POS-GIFT']]);

    $processor = new StoreTransactionReceiptProcessor(null, 'pos');
    $processor->processReceiptGroup(collect([[
        '__row_number' => 1, '__branch_id' => $f['branch']->id, 'branch' => 'NNFRZ', 'receipt_no' => '2001',
        'date' => '2026-09-16', 'posted' => '1200', 'tm' => '1', 'product_id' => 'POS-GIFT', 'product_name' => 'POS-GIFT',
        'uom' => 'PC', 'qty' => 3, 'base_qty' => 3, 'price' => 500, 'discount' => 0, 'line_total' => 1500, 'net_total' => 1500, 'take_out' => '',
    ]]));
    expect($processor->getSkippedRows())->toBe([]);

    // Someone gives the gift card a recipe afterwards: the September sale used none.
    POSMasterfileBOM::create(['POSCode' => 'POS-GIFT', 'POSDescription' => 'Gift Card', 'ItemCode' => 'RM-OAT', 'ItemDescription' => 'Oat Milk', 'BOMQty' => 10, 'BOMUOM' => 'Ml', 'UnitCost' => 0.1]);

    expect(frozenSalesQty($f, 'RM-OAT'))->toBe(0.0)
        ->and(DB::table('bom_consumptions')->count())->toBe(0);
});

it('still lists an item a frozen sale used after the item is taken out of the recipe', function () {
    $f = frozenFixture(170);
    frozenPostSale($f, '1001', 2);
    // The latte now uses oat milk only: fresh milk is in no BOM any more.
    $f['bom']->update(['ItemCode' => 'RM-OAT', 'ItemDescription' => 'Oat Milk']);
    test()->actingAs($f['user']);

    $request = Request::create('/reports/inventory-movement', 'GET', ['branch_id' => $f['branch']->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'per_page' => 50]);
    $page = app(InventoryMovementReportController::class)->index($request)->toResponse($request)->getOriginalContent()->getData()['page'];
    $rows = collect($page['props']['movementData'])->keyBy('sap_code');

    expect($rows->has('RM-MILK'))->toBeTrue()
        ->and((float) $rows['RM-MILK']['sales_qty'])->toBe(340.0)
        ->and($rows->has('RM-OAT'))->toBeFalse();
});
