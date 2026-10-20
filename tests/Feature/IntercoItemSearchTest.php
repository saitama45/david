<?php

use App\Http\Controllers\IntercoController;
use App\Models\Entity;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The interco item search reads the sending store's stock. An item the sending store has
 * none of is still returned (the page greys it out), after the items it does have.
 *
 * The controller is called directly: every test HTTP request disconnects the database
 * on terminate(), which rolls back RefreshDatabase's transaction.
 */
function intercoSearchFixture(): array
{
    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $receiving = StoreBranch::create([
        'branch_code' => 'TIR', 'brand_code' => 'TIR', 'name' => 'Receiving Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);
    $sending = StoreBranch::create([
        'branch_code' => 'TIS', 'brand_code' => 'TIS', 'name' => 'Sending Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);

    $sap = [];
    foreach ([
        ['RM-FRUIT', 'Fruit - Lemon', 'Gm', 'Kg', 1000, 1],
        ['RM-FRUIT', 'Fruit - Lemon', 'Kg', 'Kg', 1, 1],
        ['RM-REAL', 'Real Lemon', 'Ml', 'Bottle', 1000, 1],
        ['RM-REAL', 'Real Lemon', 'Bottle', 'Bottle', 1, 1],
    ] as [$code, $description, $alt, $base, $altQty, $baseQty]) {
        $sap[$code.'|'.$alt] = SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $description,
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
    }

    // The receiving store has the fruit; the sending store only has Real Lemon.
    foreach ([[$receiving, 'RM-FRUIT|Kg', 4.5], [$sending, 'RM-REAL|Bottle', 2]] as [$store, $key, $quantity]) {
        DB::table('product_inventory_stock_managers')->insert([
            'product_inventory_id' => $sap[$key], 'store_branch_id' => $store->id, 'quantity' => $quantity, 'action' => 'add',
            'unit_cost' => 0, 'total_cost' => 0, 'transaction_date' => '2026-10-01', 'is_stock_adjustment_approved' => false,
        ]);
    }

    return ['receiving' => $receiving, 'sending' => $sending, 'sap' => $sap];
}

function intercoSearch(StoreBranch $sending, string $search): array
{
    return app(IntercoController::class)
        ->getAvailableItems(Request::create('/interco/items/search', 'GET', ['sending_store_id' => $sending->id, 'search' => $search]))
        ->getData(true)['items'];
}

// Each unit's stock and availability, keyed by item and unit.
function intercoStockByUnit(array $items): array
{
    $byUnit = [];
    foreach ($items as $item) {
        $byUnit[$item['item_code'].'|'.$item['alt_uom']] = [$item['stock'], $item['is_available']];
    }
    ksort($byUnit);

    return $byUnit;
}

it('returns items the sending store has none of, after the ones it has', function () {
    $f = intercoSearchFixture();

    $items = intercoSearch($f['sending'], 'Lemon');

    expect(array_column($items, 'item_code'))->toBe(['RM-REAL', 'RM-REAL', 'RM-FRUIT', 'RM-FRUIT'])
        ->and(intercoStockByUnit($items))->toBe([
            // The receiving store's 4.5 Kg is not the sending store's stock.
            'RM-FRUIT|Gm' => ['0.00', false],
            'RM-FRUIT|Kg' => ['0.00', false],
            'RM-REAL|Bottle' => ['2.00', true],
            'RM-REAL|Ml' => ['2000.00', true],
        ]);
});

it('reads the stock of the store asked for', function () {
    $f = intercoSearchFixture();

    expect(intercoStockByUnit(intercoSearch($f['receiving'], 'Fruit')))->toBe([
        'RM-FRUIT|Gm' => ['4500.00', true],
        'RM-FRUIT|Kg' => ['4.50', true],
    ]);
});
