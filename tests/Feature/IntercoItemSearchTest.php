<?php

use App\Http\Controllers\IntercoController;
use App\Http\Requests\IntercoRequest;
use App\Models\Entity;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The Sender Store is the source of stocks: the interco item search shows its SOH, and saving
 * checks the quantity against that same balance. An item the Sender Store has none of is still
 * returned (the page greys it out), after the items it does have.
 *
 * Controllers and requests are called directly: every test HTTP request disconnects the
 * database on terminate(), which rolls back RefreshDatabase's transaction.
 */
function intercoSearchFixture(): array
{
    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $fruitStore = StoreBranch::create([
        'branch_code' => 'TIF', 'brand_code' => 'TIF', 'name' => 'Fruit Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);
    $realLemonStore = StoreBranch::create([
        'branch_code' => 'TIR', 'brand_code' => 'TIR', 'name' => 'Real Lemon Store', 'store_status' => 'Active', 'is_active' => 1,
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

    // One store has 4.5 Kg of the fruit, the other only 2 Bottle of Real Lemon.
    foreach ([[$fruitStore, 'RM-FRUIT|Kg', 4.5], [$realLemonStore, 'RM-REAL|Bottle', 2]] as [$store, $key, $quantity]) {
        DB::table('product_inventory_stock_managers')->insert([
            'product_inventory_id' => $sap[$key], 'store_branch_id' => $store->id, 'quantity' => $quantity, 'action' => 'add',
            'unit_cost' => 0, 'total_cost' => 0, 'transaction_date' => '2026-10-01', 'is_stock_adjustment_approved' => false,
        ]);
    }

    return ['fruitStore' => $fruitStore, 'realLemonStore' => $realLemonStore, 'sap' => $sap];
}

function intercoSearch(StoreBranch $sender, string $search): array
{
    return app(IntercoController::class)
        ->getAvailableItems(Request::create('/interco/items/search', 'GET', ['sending_store_id' => $sender->id, 'search' => $search]))
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

// The validation errors saving one line of a transfer from $sender to $destination gives.
function intercoSaveErrors(StoreBranch $sender, StoreBranch $destination, string $itemCode, string $uom, $quantity): array
{
    $data = [
        'sending_store_branch_id' => $sender->id, 'store_branch_id' => $destination->id,
        'interco_reason' => 'Test', 'transfer_date' => '2026-10-20',
        'items' => [[
            'item_code' => $itemCode, 'uom' => $uom, 'cost_per_quantity' => 1,
            'quantity_ordered' => $quantity, 'quantity_committed' => $quantity,
        ]],
    ];

    $request = IntercoRequest::create('/interco', 'POST', $data);
    $validator = Validator::make($data, $request->rules(), $request->messages());
    $request->withValidator($validator);

    return $validator->errors()->all();
}

it('returns items the Sender Store has none of, after the ones it has', function () {
    $f = intercoSearchFixture();

    $items = intercoSearch($f['realLemonStore'], 'Lemon');

    expect(array_column($items, 'item_code'))->toBe(['RM-REAL', 'RM-REAL', 'RM-FRUIT', 'RM-FRUIT'])
        ->and(intercoStockByUnit($items))->toBe([
            // The other store's 4.5 Kg is not this Sender Store's stock.
            'RM-FRUIT|Gm' => ['0.00', false],
            'RM-FRUIT|Kg' => ['0.00', false],
            'RM-REAL|Bottle' => ['2.00', true],
            'RM-REAL|Ml' => ['2000.00', true],
        ]);
});

it('shows the SOH of the Sender Store chosen', function () {
    $f = intercoSearchFixture();

    expect(intercoStockByUnit(intercoSearch($f['fruitStore'], 'Fruit')))->toBe([
        'RM-FRUIT|Gm' => ['4500.00', true],
        'RM-FRUIT|Kg' => ['4.50', true],
    ]);
});

it('saves a quantity below 1, and refuses 0', function () {
    $f = intercoSearchFixture();

    expect(intercoSaveErrors($f['fruitStore'], $f['realLemonStore'], 'RM-FRUIT', 'Kg', 0.5))->toBe([])
        ->and(intercoSaveErrors($f['fruitStore'], $f['realLemonStore'], 'RM-FRUIT', 'Kg', 0))
        ->toContain('Quantity must be more than 0.');
});

it('checks the Sender Store stock history, not a stock cache row', function () {
    $f = intercoSearchFixture();

    // A receiving or transfer leaves extra cache rows; the first one here says -1 Kg.
    foreach ([-1, 5.5] as $quantity) {
        DB::table('product_inventory_stocks')->insert([
            'product_inventory_id' => $f['sap']['RM-FRUIT|Kg'], 'store_branch_id' => $f['fruitStore']->id,
            'quantity' => $quantity, 'recently_added' => 0, 'used' => 0,
        ]);
    }

    expect(intercoSaveErrors($f['fruitStore'], $f['realLemonStore'], 'RM-FRUIT', 'Kg', 4.5))->toBe([])
        // 4,500 Gm is the whole 4.5 Kg, in another unit.
        ->and(intercoSaveErrors($f['fruitStore'], $f['realLemonStore'], 'RM-FRUIT', 'Gm', 4500))->toBe([])
        ->and(intercoSaveErrors($f['fruitStore'], $f['realLemonStore'], 'RM-FRUIT', 'Kg', 4.6))
        ->toBe(['Insufficient stock for item RM-FRUIT in the Sender Store. Available: 4.5, Requested: 4.6.']);
});
