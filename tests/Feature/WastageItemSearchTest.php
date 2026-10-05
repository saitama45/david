<?php

use App\Http\Controllers\WastageController;
use App\Models\Entity;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The wastage item search also finds a POS product by its code or description, and
 * offers the ingredients of its recipe - never the product itself, which holds no stock.
 *
 * The controller is called directly: every test HTTP request disconnects the database
 * on terminate(), which rolls back RefreshDatabase's transaction.
 */
function wastageSearchFixture(): array
{
    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $store = StoreBranch::create([
        'branch_code' => 'TWS', 'brand_code' => 'TWS', 'name' => 'Wastage Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);

    $sap = [];
    foreach ([
        ['RM-COCOA', 'Powder - Cocoa', 'Gm', 'Bag', 1000, 1],
        ['RM-COCOA', 'Powder - Cocoa', 'Bag', 'Bag', 1, 1],
        ['RM-SUGAR', 'Sugar - Brown', 'Kg', 'Kg', 1, 1],
        ['RM-SYRUP', 'Chocolate Syrup', 'Btl', 'Btl', 1, 1],
    ] as [$code, $description, $alt, $base, $altQty, $baseQty]) {
        $sap[$code.'|'.$alt] = SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $description,
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
    }

    wastageSearchRecipe('SP-0001', 'Chocolate Mix', [['RM-COCOA', 100, 'Gm'], ['RM-COCOA', 50, 'Gm'], ['RM-SUGAR', 100, 'Gm']]);
    wastageSearchRecipe('SP-0002', 'Vanilla Mix', [['RM-SUGAR', 20, 'Gm']]);

    // 2 Bag of cocoa on hand.
    DB::table('product_inventory_stock_managers')->insert([
        'product_inventory_id' => $sap['RM-COCOA|Bag'], 'store_branch_id' => $store->id, 'quantity' => 2, 'action' => 'add',
        'unit_cost' => 0, 'total_cost' => 0, 'transaction_date' => '2026-10-01', 'is_stock_adjustment_approved' => false,
    ]);

    return ['entity' => $entity, 'store' => $store, 'sap' => $sap];
}

function wastageSearchRecipe(string $posCode, string $posDescription, array $lines): void
{
    foreach ($lines as [$itemCode, $bomQty, $bomUom]) {
        POSMasterfileBOM::create([
            'POSCode' => $posCode, 'POSDescription' => $posDescription, 'Assembly' => 'RM',
            'ItemCode' => $itemCode, 'ItemDescription' => $itemCode, 'BOMQty' => $bomQty, 'BOMUOM' => $bomUom,
        ]);
    }
}

function wastageSearch(StoreBranch $store, string $search): array
{
    return app(WastageController::class)
        ->getAvailableItems(Request::create('/wastage/items/search', 'GET', ['store_id' => $store->id, 'search' => $search]))
        ->getData(true);
}

it('lists the ingredients of a product found by its POS code, in the unit the recipe uses', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'SP-0001');

    expect($result['more_products'])->toBeFalse()
        ->and(array_column($result['items'], 'item_code'))->toBe(['RM-COCOA', 'RM-SUGAR'])
        ->and($result['items'][0])->toMatchArray([
            // The recipe unit, with the item's two lines added up and its 2 Bag shown as grams.
            'id' => $f['sap']['RM-COCOA|Gm'], 'alt_uom' => 'Gm', 'recipe_qty' => 150, 'recipe_uom' => 'Gm', 'stock' => 2000,
            'product' => ['code' => 'SP-0001', 'description' => 'Chocolate Mix'],
        ])
        ->and($result['items'][1])->toMatchArray([
            // Gm is not a unit SAP gives the sugar, so it is offered in the unit its stock is kept in.
            'id' => $f['sap']['RM-SUGAR|Kg'], 'alt_uom' => 'Kg', 'recipe_qty' => 100, 'recipe_uom' => 'Gm',
        ]);
});

it('finds a product by its description and lists the matching items first', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'Chocolate');

    expect(array_map(fn ($item) => [$item['item_code'], $item['product']['code'] ?? null], $result['items']))->toBe([
        ['RM-SYRUP', null],
        ['RM-COCOA', 'SP-0001'],
        ['RM-SUGAR', 'SP-0001'],
    ]);
});

it('lists an ingredient under every product that uses it and never the product itself', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'Mix');

    expect(array_map(fn ($item) => [$item['item_code'], $item['product']['code']], $result['items']))->toBe([
        ['RM-COCOA', 'SP-0001'],
        ['RM-SUGAR', 'SP-0001'],
        ['RM-SUGAR', 'SP-0002'],
    ])->and(array_column($result['items'], 'item_code'))->not->toContain('SP-0001', 'SP-0002');
});

it('keeps an item search as it was', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'Cocoa');

    // Every unit of the item, and nothing about a recipe.
    expect(array_column($result['items'], 'alt_uom'))->toBe(['Gm', 'Bag'])
        ->and($result['items'][0])->not->toHaveKey('product')
        ->and($result['more_products'])->toBeFalse();
});

it('does not list the products of another entity', function () {
    $f = wastageSearchFixture();

    $other = Entity::create(['name' => 'Other Entity', 'code' => 'OE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($other->id);
    wastageSearchRecipe('SP-9999', 'Hazelnut Mix', [['RM-SUGAR', 5, 'Gm']]);
    app(EntityContext::class)->set($f['entity']->id);

    expect(wastageSearch($f['store'], 'Hazelnut')['items'])->toBe([]);
});

it('lists every ingredient of a product, however many it has', function () {
    $f = wastageSearchFixture();

    $codes = [];
    foreach (range(1, 25) as $n) {
        $codes[] = $code = sprintf('RM-BIG-%02d', $n);
        SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => 'Platter Part '.$n,
            'AltUOM' => 'Pc', 'BaseUOM' => 'Pc', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
        ]);
    }
    wastageSearchRecipe('SP-BIG', 'Big Party Tray', array_map(fn ($code) => [$code, 1, 'Pc'], $codes));

    $result = wastageSearch($f['store'], 'Big Party Tray');

    expect(array_column($result['items'], 'item_code'))->toBe($codes)
        ->and($result['more_products'])->toBeFalse();
});

it('lists ten products at most and says more matched', function () {
    $f = wastageSearchFixture();

    foreach (range(1, 11) as $n) {
        wastageSearchRecipe(sprintf('BD-%02d', $n), sprintf('Bulk Drink %02d', $n), [['RM-SUGAR', $n, 'Gm']]);
    }

    $result = wastageSearch($f['store'], 'Bulk Drink');

    expect($result['more_products'])->toBeTrue()
        ->and(array_map(fn ($item) => $item['product']['code'], $result['items']))
        ->toBe(array_map(fn ($n) => sprintf('BD-%02d', $n), range(1, 10)));
});
