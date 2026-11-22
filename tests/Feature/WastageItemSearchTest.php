<?php

use App\Http\Controllers\WastageController;
use App\Http\Services\WastageApprovalSettingsService;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The wastage item search finds SAP items, and a Sub-Prep by its POS code or description.
 * A finished product (any other POS product) is not listed, nor are its ingredients.
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


function wastageSearchSubPrep(string $posCode, string $posDescription, array $lines): void
{
    POSMasterfile::create([
        'POSCode' => $posCode, 'POSDescription' => $posDescription, 'Category' => 'Sub-Prep', 'UOM' => 'Gm', 'SRP' => 0, 'is_active' => true,
    ]);
    wastageSearchRecipe($posCode, $posDescription, $lines);
}

it('lists neither a finished product found by its POS code nor its ingredients', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'SP-0001');

    expect($result['items'])->toBe([])
        ->and($result['more_products'])->toBeFalse();
});

it('finds items by their description and leaves a finished product of the same name out', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'Chocolate');

    expect(array_map(fn ($item) => [$item['item_code'], $item['product']['code'] ?? null], $result['items']))->toBe([
        ['RM-SYRUP', null],
    ]);
});

it('keeps an item search as it was', function () {
    $f = wastageSearchFixture();

    $result = wastageSearch($f['store'], 'Cocoa');

    // Every unit of the item, and nothing about a recipe.
    expect(array_column($result['items'], 'alt_uom'))->toBe(['Gm', 'Bag'])
        ->and($result['items'][0])->not->toHaveKey('product')
        ->and($result['more_products'])->toBeFalse();
});

it('still finds a Sub-Prep by its POS code, as itself', function () {
    $f = wastageSearchFixture();
    wastageSearchSubPrep('SP-0100', 'Hazelnut Base', [['RM-SUGAR', 5, 'Gm']]);

    $result = wastageSearch($f['store'], 'SP-0100');

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0])->toMatchArray(['sub_prep' => true, 'item_code' => 'SP-0100'])
        ->and($result['items'][0]['product'])->toMatchArray(['code' => 'SP-0100', 'sub_prep' => true]);
});

it('does not list the Sub-Preps of another entity', function () {
    $f = wastageSearchFixture();

    $other = Entity::create(['name' => 'Other Entity', 'code' => 'OE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($other->id);
    wastageSearchSubPrep('SP-9999', 'Hazelnut Mix', [['RM-SUGAR', 5, 'Gm']]);
    app(EntityContext::class)->set($f['entity']->id);

    expect(wastageSearch($f['store'], 'Hazelnut')['items'])->toBe([]);
});

it('lists ten Sub-Preps at most and says more matched', function () {
    $f = wastageSearchFixture();

    foreach (range(1, 11) as $n) {
        wastageSearchSubPrep(sprintf('BD-%02d', $n), sprintf('Bulk Base %02d', $n), [['RM-SUGAR', $n, 'Gm']]);
    }

    $result = wastageSearch($f['store'], 'Bulk Base');

    expect($result['more_products'])->toBeTrue()
        ->and(array_map(fn ($item) => $item['product']['code'], $result['items']))
        ->toBe(array_map(fn ($n) => sprintf('BD-%02d', $n), range(1, 10)));
});

it('says whether out-of-stock items may be added, as the active entity set it in Wastage Settings', function () {
    $f = wastageSearchFixture();
    $settings = app(WastageApprovalSettingsService::class);

    expect(wastageSearch($f['store'], 'Sugar')['allow_out_of_stock'])->toBeFalse();

    $settings->setAllowOutOfStockItems(true);

    $other = Entity::create(['name' => 'Other Entity', 'code' => 'OE'.uniqid(), 'is_active' => true]);

    expect(wastageSearch($f['store'], 'Sugar')['allow_out_of_stock'])->toBeTrue()
        ->and($settings->allowsOutOfStockItems($other->id))->toBeFalse();
});
