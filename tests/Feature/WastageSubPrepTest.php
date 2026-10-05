<?php

use App\Enums\WastageStatus;
use App\Http\Controllers\WastageController;
use App\Http\Requests\WastageRequest;
use App\Http\Services\InventoryMovementService;
use App\Http\Services\WastageService;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Wastage;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A Sub-Prep (a POS item of the Sub-Prep category) is wasted as itself, valued at its SRP.
 * It holds no stock: approval deducts, and the Inventory Movement Report charges, the raw
 * materials on its BOM - the BOM Qty of each for every unit wasted, as a sale would.
 *
 * Controllers and services are called directly: every test HTTP request disconnects the
 * database on terminate(), which rolls back RefreshDatabase's transaction.
 */
function subPrepFixture(array $stock = ['RM-COCOA' => 2, 'RM-SUGAR' => 1]): array
{
    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $store = StoreBranch::create([
        'branch_code' => 'TSP', 'brand_code' => 'TSP', 'name' => 'Sub-Prep Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);

    // Cocoa is kept by the Bag (1000 Gm), sugar by the Kg (1000 Gm); recipes use grams.
    $sap = [];
    foreach ([
        ['RM-COCOA', 'Powder - Cocoa', 'Gm', 'Bag', 1000, 1],
        ['RM-COCOA', 'Powder - Cocoa', 'Bag', 'Bag', 1, 1],
        ['RM-SUGAR', 'Sugar - Brown', 'Gm', 'Kg', 1000, 1],
        ['RM-SUGAR', 'Sugar - Brown', 'Kg', 'Kg', 1, 1],
    ] as [$code, $description, $alt, $base, $altQty, $baseQty]) {
        $sap[$code.'|'.$alt] = SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $description,
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
    }

    $subPrep = POSMasterfile::create([
        'POSCode' => 'SP-0001', 'POSDescription' => 'Chocolate Mix', 'Category' => 'Sub-Prep', 'UOM' => 'ml', 'SRP' => 12.5, 'is_active' => true,
    ]);
    $drink = POSMasterfile::create([
        'POSCode' => 'FG-0001', 'POSDescription' => 'Mocha Latte', 'Category' => 'Signature Beverages', 'UOM' => 'Cup', 'SRP' => 180, 'is_active' => true,
    ]);

    // 1 ml of Chocolate Mix uses 2 Gm of cocoa and 3 Gm of sugar.
    foreach ([
        ['SP-0001', 'Chocolate Mix', 'RM-COCOA', 'Powder - Cocoa', 2, 0.5],
        ['SP-0001', 'Chocolate Mix', 'RM-SUGAR', 'Sugar - Brown', 3, 0.1],
        ['FG-0001', 'Mocha Latte', 'RM-COCOA', 'Powder - Cocoa', 10, 0.5],
    ] as [$posCode, $posDescription, $itemCode, $itemDescription, $bomQty, $unitCost]) {
        POSMasterfileBOM::create([
            'POSCode' => $posCode, 'POSDescription' => $posDescription, 'Assembly' => 'RM', 'ItemCode' => $itemCode,
            'ItemDescription' => $itemDescription, 'BOMQty' => $bomQty, 'BOMUOM' => 'Gm', 'UnitCost' => $unitCost,
        ]);
    }

    // On hand, in the unit each item is kept in: the search reads the stock history, the approval the balance.
    foreach (['RM-COCOA' => 'Bag', 'RM-SUGAR' => 'Kg'] as $code => $unit) {
        if (($stock[$code] ?? 0) == 0) {
            continue;
        }

        DB::table('product_inventory_stock_managers')->insert([
            'product_inventory_id' => $sap[$code.'|'.$unit], 'store_branch_id' => $store->id, 'quantity' => $stock[$code], 'action' => 'add',
            'unit_cost' => 0, 'total_cost' => 0, 'transaction_date' => '2026-10-01', 'is_stock_adjustment_approved' => false,
        ]);
        ProductInventoryStock::create([
            'product_inventory_id' => $sap[$code.'|'.$unit], 'store_branch_id' => $store->id,
            'quantity' => $stock[$code], 'recently_added' => 0, 'used' => 0,
        ]);
    }

    return compact('entity', 'user', 'store', 'sap', 'subPrep', 'drink');
}

function subPrepSearch(array $f, string $search): array
{
    return app(WastageController::class)
        ->getAvailableItems(Request::create('/wastage/items/search', 'GET', ['store_id' => $f['store']->id, 'search' => $search]))
        ->getData(true)['items'];
}

function subPrepWastage(array $f, float $qty, WastageStatus $status, string $reason = 'Spoilage'): \Illuminate\Support\Collection
{
    Wastage::create([
        'store_branch_id' => $f['store']->id, 'wastage_no' => 'WS-SP-1', 'pos_masterfile_id' => $f['subPrep']->id,
        'wastage_qty' => $qty, 'approverlvl1_qty' => $qty, 'approverlvl2_qty' => $status === WastageStatus::APPROVED_LVL2 ? $qty : null,
        'cost' => 12.5, 'reason' => $reason, 'wastage_status' => $status->value, 'created_by' => $f['user']->id,
    ]);

    return Wastage::where('wastage_no', 'WS-SP-1')->with(['sapMasterfile', 'posMasterfile'])->get();
}

function subPrepBalance(array $f, string $key): float
{
    return (float) ProductInventoryStock::where('product_inventory_id', $f['sap'][$key])->where('store_branch_id', $f['store']->id)->value('quantity');
}

it('offers a Sub-Prep as the row to add, at its SRP, with its raw materials for information only', function () {
    $f = subPrepFixture();

    $rows = subPrepSearch($f, 'Chocolate Mix');

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray([
            'sub_prep' => true, 'pos_masterfile_id' => $f['subPrep']->id, 'id' => null,
            'item_code' => 'SP-0001', 'description' => 'Chocolate Mix', 'alt_uom' => 'ml', 'cost_per_quantity' => 12.5,
            // 2,000 Gm of cocoa covers 1,000 ml and 1,000 Gm of sugar 333.33 ml: the least of the two.
            'stock' => 333.3333, 'blocked_reason' => null,
        ])
        ->and($rows[0]['product'])->toBe(['code' => 'SP-0001', 'description' => 'Chocolate Mix', 'sub_prep' => true, 'uom' => 'ml'])
        ->and(array_map(fn ($row) => [$row['item_code'], $row['info_only'], $row['recipe_qty'], $row['stock']], array_slice($rows, 1)))
        ->toBe([['RM-COCOA', true, 2, 2000], ['RM-SUGAR', true, 3, 1000]]);
});

it('keeps the ingredients of any other product addable', function () {
    $f = subPrepFixture();

    $rows = subPrepSearch($f, 'Mocha Latte');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['item_code'])->toBe('RM-COCOA')
        ->and($rows[0])->not->toHaveKey('info_only')
        ->and($rows[0])->not->toHaveKey('sub_prep');
});

it('blocks a Sub-Prep while a raw material is out of stock, and one with no UOM', function () {
    $f = subPrepFixture(['RM-COCOA' => 2, 'RM-SUGAR' => 0]);

    expect(subPrepSearch($f, 'SP-0001')[0])->toMatchArray(['stock' => 0, 'blocked_reason' => 'Out of stock: Sugar - Brown']);

    $f['subPrep']->update(['UOM' => null]);

    expect(subPrepSearch($f, 'SP-0001')[0]['blocked_reason'])->toBe('No UOM in the POS Masterlist');
});

it('accepts only a Sub-Prep as a POS line, and never a line that is both', function () {
    $f = subPrepFixture();
    $errors = function (array $line) use ($f) {
        $request = WastageRequest::create('/wastage', 'POST', [
            'store_branch_id' => $f['store']->id, 'wastage_date' => now('Asia/Manila')->toDateString(), 'remarks' => 'Test',
            'cartItems' => [array_merge(['quantity' => 5, 'cost' => 1, 'reason' => 'Spoilage', 'images' => [UploadedFile::fake()->image('proof.jpg')]], $line)],
        ]);

        return Validator::make($request->all(), $request->rules())->errors()->keys();
    };

    expect($errors(['pos_masterfile_id' => $f['subPrep']->id]))->toBe([])
        ->and($errors(['sap_masterfile_id' => $f['sap']['RM-COCOA|Gm']]))->toBe([])
        ->and($errors(['pos_masterfile_id' => $f['drink']->id]))->toBe(['cartItems.0.pos_masterfile_id'])
        ->and($errors([]))->toContain('cartItems.0.sap_masterfile_id')
        ->and($errors(['pos_masterfile_id' => $f['subPrep']->id, 'sap_masterfile_id' => $f['sap']['RM-COCOA|Gm']]))->toContain('cartItems.0.sap_masterfile_id');
});

it('files a Sub-Prep line at the SRP, whatever cost the form sent', function () {
    $f = subPrepFixture();

    $created = app(WastageService::class)->createMultipleWastageRecords([
        'store_branch_id' => $f['store']->id, 'wastage_date' => '2026-10-05', 'remarks' => 'Spilled',
        'cartItems' => [['pos_masterfile_id' => $f['subPrep']->id, 'quantity' => 5, 'cost' => 999, 'reason' => 'Spoilage']],
    ], $f['user']->id);

    $line = Wastage::with(['sapMasterfile', 'posMasterfile'])->findOrFail($created->first()->id);

    expect($line->sap_masterfile_id)->toBeNull()
        ->and((int) $line->pos_masterfile_id)->toBe($f['subPrep']->id)
        ->and((float) $line->cost)->toBe(12.5)
        ->and($line->lineItem())->toBe([
            'id' => null, 'ItemCode' => 'SP-0001', 'ItemDescription' => 'Chocolate Mix', 'BaseUOM' => 'ml', 'AltUOM' => 'ml', 'sub_prep' => true,
        ]);
});

it('deducts the raw materials of an approved Sub-Prep through its BOM', function () {
    $f = subPrepFixture();
    $wastages = subPrepWastage($f, 5, WastageStatus::APPROVED_LVL1);

    app(WastageService::class)->finalizeWastageApproval($wastages, $f['store']->id, $f['user']->id, 'level2');

    // 5 ml used 10 Gm of cocoa (0.01 Bag) and 15 Gm of sugar (0.015 Kg).
    expect(round(subPrepBalance($f, 'RM-COCOA|Bag'), 4))->toBe(1.99)
        ->and(round(subPrepBalance($f, 'RM-SUGAR|Kg'), 4))->toBe(0.985)
        ->and($wastages->first()->fresh()->wastage_status)->toBe(WastageStatus::APPROVED_LVL2);

    $movements = DB::table('product_inventory_stock_managers')->where('action', 'out')->where('remarks', 'like', '%WS-SP-1')
        ->get()->keyBy('product_inventory_id');

    expect($movements)->toHaveCount(2)
        ->and(round((float) $movements[$f['sap']['RM-COCOA|Bag']]->quantity, 4))->toBe(0.01)
        // At the BOM's cost of the raw material: 10 Gm x 0.50 and 15 Gm x 0.10.
        ->and(round((float) $movements[$f['sap']['RM-COCOA|Bag']]->total_cost, 2))->toBe(5.0)
        ->and(round((float) $movements[$f['sap']['RM-SUGAR|Kg']]->quantity, 4))->toBe(0.015)
        ->and(round((float) $movements[$f['sap']['RM-SUGAR|Kg']]->total_cost, 2))->toBe(1.5);
});

it('lists the raw material a Sub-Prep approval would take below zero', function () {
    $f = subPrepFixture();
    // 1,000 ml needs 2 Bag of cocoa, which is on hand, and 3 Kg of sugar, of which there is 1.
    $wastages = subPrepWastage($f, 1000, WastageStatus::APPROVED_LVL1);

    $check = app(WastageService::class)->getApprovalStockCheck($wastages, $f['store']->id, 'level2');

    expect($check['errors'])->toBe([])
        ->and($check['negative'])->toHaveCount(1)
        ->and($check['negative'][0])->toMatchArray(['item_code' => 'RM-SUGAR', 'uom' => 'Kg', 'available' => 1.0, 'required' => 3.0, 'resulting' => -2.0]);
});

it('deducts nothing for a Sub-Prep filed as Scrap, as for any other item', function () {
    $f = subPrepFixture();
    $wastages = subPrepWastage($f, 5, WastageStatus::APPROVED_LVL1, 'Scrap');

    app(WastageService::class)->finalizeWastageApproval($wastages, $f['store']->id, $f['user']->id, 'level2');

    expect(subPrepBalance($f, 'RM-COCOA|Bag'))->toBe(2.0)
        ->and(subPrepBalance($f, 'RM-SUGAR|Kg'))->toBe(1.0);
});

it('charges an approved Sub-Prep wastage to its raw materials in the Inventory Movement Report', function () {
    $f = subPrepFixture();
    subPrepWastage($f, 5, WastageStatus::APPROVED_LVL2);
    // A pending one is not counted.
    Wastage::create([
        'store_branch_id' => $f['store']->id, 'wastage_no' => 'WS-SP-2', 'pos_masterfile_id' => $f['subPrep']->id,
        'wastage_qty' => 100, 'cost' => 12.5, 'reason' => 'Spoilage', 'wastage_status' => WastageStatus::PENDING->value, 'created_by' => $f['user']->id,
    ]);
    // Cocoa wasted directly, by the gram, on top of what the Sub-Prep used.
    Wastage::create([
        'store_branch_id' => $f['store']->id, 'wastage_no' => 'WS-SP-3', 'sap_masterfile_id' => $f['sap']['RM-COCOA|Gm'],
        'wastage_qty' => 40, 'approverlvl2_qty' => 40, 'cost' => 1, 'reason' => 'Spoilage',
        'wastage_status' => WastageStatus::APPROVED_LVL2->value, 'created_by' => $f['user']->id,
    ]);

    $today = now('Asia/Manila')->toDateString();
    $rows = collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::whereIn('ItemCode', ['RM-COCOA', 'RM-SUGAR'])->get(),
        ['branch_id' => $f['store']->id, 'date_from' => $today, 'date_to' => $today]
    ))->keyBy('sap_code');

    // In each item's base unit: (10 + 40) Gm of cocoa = 0.05 Bag, 15 Gm of sugar = 0.015 Kg.
    expect($rows['RM-COCOA'])->toMatchArray(['uom' => 'Bag', 'wastage_qty' => 0.05, 'theoretical_qty' => -0.05, 'unconverted_units' => []])
        ->and($rows['RM-SUGAR'])->toMatchArray(['uom' => 'Kg', 'wastage_qty' => 0.015, 'theoretical_qty' => -0.015, 'unconverted_units' => []])
        // The report says how much of each figure is the Sub-Prep: 0.01 of cocoa's 0.05 Bag, all of the sugar.
        ->and($rows['RM-COCOA']['wastage_sub_preps'])->toBe([
            ['code' => 'SP-0001', 'description' => 'Chocolate Mix', 'uom' => 'ml', 'wasted_qty' => 5.0, 'quantity' => 0.01],
        ])
        ->and($rows['RM-SUGAR']['wastage_sub_preps'])->toBe([
            ['code' => 'SP-0001', 'description' => 'Chocolate Mix', 'uom' => 'ml', 'wasted_qty' => 5.0, 'quantity' => 0.015],
        ]);
});

it('notes the Sub-Prep on the wastage cell of the Excel export', function () {
    $f = subPrepFixture();
    subPrepWastage($f, 5, WastageStatus::APPROVED_LVL2);

    $today = now('Asia/Manila')->toDateString();
    $filters = ['branch_id' => $f['store']->id, 'date_from' => $today, 'date_to' => $today];
    $rows = collect(app(InventoryMovementService::class)->movementData(SAPMasterfile::whereIn('ItemCode', ['RM-COCOA'])->get(), $filters))->values()->all();

    $path = tempnam(sys_get_temp_dir(), 'imr');
    file_put_contents($path, \Maatwebsite\Excel\Facades\Excel::raw(
        new \App\Exports\InventoryMovementReportExport($rows, $filters, $f['store'], null, 'QA Tester', '2026-10-05 10:00:00'),
        \Maatwebsite\Excel\Excel::XLSX
    ));
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
    @unlink($path);

    // Row 6 is the first item; column J is Wastage Qty, still a number.
    expect($sheet->getCell('B6')->getValue())->toBe('RM-COCOA')
        ->and((float) $sheet->getCell('J6')->getValue())->toBe(0.01)
        ->and($sheet->getComment('J6')->getText()->getPlainText())->toBe('Sub-Prep SP-0001 Chocolate Mix: 5 ml wasted = 0.01 Bag of this item');
});

it('marks no Sub-Prep on wastage filed for the item itself', function () {
    $f = subPrepFixture();
    Wastage::create([
        'store_branch_id' => $f['store']->id, 'wastage_no' => 'WS-SP-4', 'sap_masterfile_id' => $f['sap']['RM-COCOA|Gm'],
        'wastage_qty' => 40, 'approverlvl2_qty' => 40, 'cost' => 1, 'reason' => 'Spoilage',
        'wastage_status' => WastageStatus::APPROVED_LVL2->value, 'created_by' => $f['user']->id,
    ]);

    $today = now('Asia/Manila')->toDateString();
    $row = collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::whereIn('ItemCode', ['RM-COCOA'])->get(),
        ['branch_id' => $f['store']->id, 'date_from' => $today, 'date_to' => $today]
    ))->firstWhere('sap_code', 'RM-COCOA');

    expect($row['wastage_qty'])->toBe(0.04)
        ->and($row['wastage_sub_preps'])->toBe([]);
});
