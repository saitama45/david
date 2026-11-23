<?php

use App\Http\Controllers\InventoryMovementReportController;
use App\Http\Controllers\WastageController;
use App\Http\Services\InventoryMovementDetailService;
use App\Http\Services\InventoryMovementService;
use App\Models\Entity;
use App\Models\MonthEndSchedule;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Wastage;
use App\Services\MonthEndStockVariance;
use App\Services\StoreTransactionReceiptProcessor;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * A Sub-Prep (a prep mix: SP-0001 Matcha Prep Mix, 430 Gm = 1 Batch) holds no stock. A sale
 * whose BOM uses it, a month end count of it and wastage against its SAP row are all charged
 * to the raw materials on its BOM, and the Inventory Movement Report never lists the mix.
 *
 * Runs on an isolated test database; services and controllers are called directly (a test
 * HTTP request disconnects the database and rolls RefreshDatabase back). Nothing here deletes
 * a record or soft-deletes one.
 */
afterEach(fn () => Carbon::setTestNow());

function subPrepMixFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00', 'Asia/Manila'));

    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    Permission::firstOrCreate(['name' => 'view inventory movement report']);
    $user = User::factory()->create();
    $user->givePermissionTo('view inventory movement report');

    $store = StoreBranch::create(['branch_code' => 'TCSP', 'brand_code' => 'TCSP', 'name' => 'Mix Store', 'store_status' => 'Active', 'is_active' => 1]);
    $user->store_branches()->attach($store->id);

    // As TC's SAP Masterlist gives them: the mix is on it too, as 430 Gm = 1 Batch with no Batch row.
    $sap = [];
    foreach ([
        ['RM-MATCHA', 'Matcha Powder', 'Gm', 'Gm', 1, 1], ['RM-MATCHA', 'Matcha Powder', 'Bag', 'Gm', 1, 1000],
        ['RM-SUGAR', 'White Sugar', 'Gm', 'Gm', 1, 1],
        ['SP-0001', 'Matcha Prep Mix', 'Gm', 'Gm', 1, 1], ['SP-0001', 'Matcha Prep Mix', 'Gm', 'Batch', 430, 1],
    ] as $i => [$code, $description, $alt, $base, $altQty, $baseQty]) {
        $id = SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $description,
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
        $sap[$code.'|'.$alt.'|'.$base] = $id;
    }

    // One Batch of the mix is 30 Gm of matcha and 400 Gm of sugar.
    POSMasterfile::create(['POSCode' => 'SP-0001', 'POSDescription' => 'Matcha Prep Mix', 'Category' => 'SUB-PREP', 'UOM' => 'Batch', 'SRP' => 0, 'is_active' => true]);
    POSMasterfile::create(['POSCode' => 'TC-ICHIGO', 'POSDescription' => 'Ichigo Matcha', 'Category' => 'Beverages', 'UOM' => 'Cup', 'SRP' => 180, 'is_active' => true]);
    foreach ([
        ['SP-0001', 'Matcha Prep Mix', 'RM-MATCHA', 'Matcha Powder', 30, 'Gm', 4.2],
        ['SP-0001', 'Matcha Prep Mix', 'RM-SUGAR', 'White Sugar', 400, 'Gm', 0.08],
        // A cup uses 43 Gm of the mix (0.1 Batch) and 5 Gm of sugar on top.
        ['TC-ICHIGO', 'Ichigo Matcha', 'SP-0001', 'Matcha Prep Mix', 43, 'Gm', 0.4],
        ['TC-ICHIGO', 'Ichigo Matcha', 'RM-SUGAR', 'White Sugar', 5, 'Gm', 0.08],
    ] as [$pos, $posDescription, $item, $itemDescription, $qty, $uom, $cost]) {
        POSMasterfileBOM::create([
            'POSCode' => $pos, 'POSDescription' => $posDescription, 'ItemCode' => $item, 'ItemDescription' => $itemDescription,
            'BOMQty' => $qty, 'BOMUOM' => $uom, 'UnitCost' => $cost,
        ]);
    }

    $september = MonthEndSchedule::create(['year' => 2026, 'month' => 9, 'calculated_date' => '2026-09-30', 'created_by' => $user->id]);
    $october = MonthEndSchedule::create(['year' => 2026, 'month' => 10, 'calculated_date' => '2026-10-31', 'created_by' => $user->id]);

    return compact('entity', 'user', 'store', 'sap', 'september', 'october');
}

function subPrepMixSale(array $f, string $receipt, int $cups, string $date = '2026-10-05'): void
{
    $processor = new StoreTransactionReceiptProcessor(null, 'pos');
    $sale = $processor->processReceiptGroup(collect([[
        '__row_number' => 1, '__branch_id' => $f['store']->id, 'branch' => 'TCSP', 'receipt_no' => $receipt,
        'date' => $date, 'posted' => '1200', 'tm' => '1', 'product_id' => 'TC-ICHIGO', 'product_name' => 'Ichigo Matcha',
        'uom' => 'Cup', 'qty' => $cups, 'base_qty' => $cups, 'price' => 180, 'discount' => 0,
        'line_total' => 180 * $cups, 'net_total' => 180 * $cups, 'take_out' => '',
    ]]));

    expect($processor->getSkippedRows())->toBe([])->and($sale)->not->toBeNull();
}

function subPrepMixCount(array $f, MonthEndSchedule $schedule, string $sapKey, string $uom, float $qty): void
{
    DB::table('month_end_count_items')->insert([
        'entity_id' => $f['entity']->id, 'month_end_schedule_id' => $schedule->id, 'branch_id' => $f['store']->id,
        'sap_masterfile_id' => $f['sap'][$sapKey], 'item_code' => explode('|', $sapKey)[0], 'item_name' => explode('|', $sapKey)[0],
        'uom' => $uom, 'total_qty' => $qty, 'status' => 'level2_approved', 'level2_approved_at' => now(), 'created_by' => $f['user']->id,
    ]);
}

/** Everything a mix can carry in October, plus the September count that is October's Beg Bal. */
function subPrepMixMovements(array $f): void
{
    // Posted now: 2 cups = 86 Gm of mix = 0.2 Batch -> 6 Gm matcha, 80 Gm sugar, and 10 Gm sugar of the cup's own.
    subPrepMixSale($f, '2001', 2);

    // Posted before the mix was broken down: the sale line was filed under the mix, 430 Gm = 1 Batch.
    $legacy = DB::table('store_transactions')->insertGetId([
        'entity_id' => $f['entity']->id, 'store_branch_id' => $f['store']->id, 'order_date' => '2026-10-03', 'posted' => 'Y',
        'tim_number' => '1', 'receipt_number' => 'OLD-1', 'bom_frozen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('bom_consumptions')->insert([
        'entity_id' => $f['entity']->id, 'store_branch_id' => $f['store']->id, 'business_date' => '2026-10-03', 'source_type' => 'sale',
        'store_transaction_id' => $legacy, 'pos_code' => 'TC-ICHIGO', 'pos_description' => 'Ichigo Matcha', 'item_code' => 'SP-0001',
        'bom_uom' => 'Gm', 'bom_qty' => '43.0000000', 'units' => '10.0000', 'qty' => '430.0000000000', 'origin' => 'posted',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Counted: 2 Batch of mix in September beside 50 Gm of powder and 1,000 Gm of sugar, and
    // 215 Gm (0.5 Batch) of mix in October beside 100 Gm of powder and 300 Gm of sugar.
    subPrepMixCount($f, $f['september'], 'SP-0001|Gm|Gm', 'Batch', 2);
    subPrepMixCount($f, $f['september'], 'RM-MATCHA|Gm|Gm', 'Gm', 50);
    subPrepMixCount($f, $f['september'], 'RM-SUGAR|Gm|Gm', 'Gm', 1000);
    subPrepMixCount($f, $f['october'], 'SP-0001|Gm|Gm', 'Gm', 215);
    subPrepMixCount($f, $f['october'], 'RM-MATCHA|Gm|Gm', 'Gm', 100);
    subPrepMixCount($f, $f['october'], 'RM-SUGAR|Gm|Gm', 'Gm', 300);

    // Wasted against the mix's SAP row: 43 Gm = 0.1 Batch.
    Wastage::create([
        'store_branch_id' => $f['store']->id, 'wastage_no' => 'WS-MIX-1', 'sap_masterfile_id' => $f['sap']['SP-0001|Gm|Gm'],
        'wastage_qty' => 43, 'approverlvl2_qty' => 43, 'cost' => 1, 'reason' => 'Spoilage',
        'wastage_status' => 'approved_lvl2', 'created_by' => $f['user']->id,
    ]);
}

function subPrepMixReport(array $f): \Illuminate\Support\Collection
{
    return collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::where('is_active', true)->get(),
        ['branch_id' => $f['store']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']
    ))->keyBy('sap_code');
}

function subPrepMixPopup(array $f, string $itemCode, string $metric): array
{
    return app(InventoryMovementDetailService::class)->details(
        SAPMasterfile::where('is_active', true)->where('ItemCode', $itemCode)->orderBy('id')->get(), $f['store']->id,
        ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $metric, 1, null
    );
}

it('posts a sale whose BOM uses a Sub-Prep against the raw materials of the mix, never the mix itself', function () {
    $f = subPrepMixFixture();
    subPrepMixSale($f, '2001', 2);

    $movements = DB::table('product_inventory_stock_managers')->where('store_branch_id', $f['store']->id)->get();
    $out = $movements->groupBy(fn ($row) => (int) $row->product_inventory_id)->map(fn ($rows) => round((float) $rows->sum('quantity'), 6));

    expect($out->keys()->sort()->values()->all())->toBe(collect([$f['sap']['RM-MATCHA|Gm|Gm'], $f['sap']['RM-SUGAR|Gm|Gm']])->sort()->values()->all())
        // 2 cups x 43 Gm of mix = 0.2 Batch: 6 Gm of matcha, 80 Gm of sugar, plus the cups' own 10 Gm.
        ->and($out[$f['sap']['RM-MATCHA|Gm|Gm']])->toBe(6.0)
        ->and($out[$f['sap']['RM-SUGAR|Gm|Gm']])->toBe(90.0)
        ->and($movements->pluck('action')->unique()->values()->all())->toBe(['out'])
        ->and($movements->firstWhere('product_inventory_id', $f['sap']['RM-MATCHA|Gm|Gm'])->remarks)->toContain('via Sub-Prep SP-0001');

    // The frozen lines say which part came through the mix.
    $lines = DB::table('bom_consumptions')->where('source_type', 'sale')->get();
    expect($lines->pluck('item_code')->unique()->sort()->values()->all())->toBe(['RM-MATCHA', 'RM-SUGAR'])
        ->and($lines->where('item_code', 'RM-MATCHA')->first()->sub_prep_code)->toBe('SP-0001')
        ->and($lines->where('item_code', 'RM-MATCHA')->first()->sub_prep_description)->toBe('Matcha Prep Mix')
        ->and($lines->whereNull('sub_prep_code')->pluck('item_code')->all())->toBe(['RM-SUGAR'])
        ->and(round((float) $lines->sum('qty'), 6))->toBe(96.0);
});

it('never lists a Sub-Prep in the Inventory Movement Report and charges its counts, sales and wastage to its raw materials', function () {
    $f = subPrepMixFixture();
    subPrepMixMovements($f);

    $rows = subPrepMixReport($f);
    $matcha = $rows['RM-MATCHA'];
    $sugar = $rows['RM-SUGAR'];

    expect($rows->has('SP-0001'))->toBeFalse()
        ->and($matcha['uom'])->toBe('Gm')
        // Beg Bal: what was found loose, plus 2 Batch of mix = 60 Gm of matcha, 800 Gm of sugar.
        ->and($matcha['beg_bal_qty'])->toBe(110.0)
        ->and($sugar['beg_bal_qty'])->toBe(1800.0)
        // Sales: 6 Gm from the cups posted now and 30 Gm from the 430 Gm of mix filed earlier.
        ->and($matcha['sales_qty'])->toBe(36.0)
        ->and($sugar['sales_qty'])->toBe(490.0)
        // Wastage against the mix's own row: 0.1 Batch.
        ->and($matcha['wastage_qty'])->toBe(3.0)
        ->and($sugar['wastage_qty'])->toBe(40.0)
        // Actual MEC: 100 Gm of powder plus the 15 Gm inside the 0.5 Batch of mix counted.
        ->and($matcha['actual_mec'])->toBe(115.0)
        ->and($sugar['actual_mec'])->toBe(500.0)
        ->and($matcha['theoretical_qty'])->toBe(71.0)
        ->and($matcha['unconverted_units'])->toBe([]);

    // Each figure says how much of it is the mix.
    $parts = $matcha['sub_prep_parts'];
    ksort($parts);
    expect($parts)->toBe([
        'actual_mec' => [['code' => 'SP-0001', 'description' => 'Matcha Prep Mix', 'quantity' => 15.0]],
        'beg_bal' => [['code' => 'SP-0001', 'description' => 'Matcha Prep Mix', 'quantity' => 60.0]],
        'sales' => [['code' => 'SP-0001', 'description' => 'Matcha Prep Mix', 'quantity' => 36.0]],
        'wastage' => [['code' => 'SP-0001', 'description' => 'Matcha Prep Mix', 'quantity' => 3.0]],
    ])->and($sugar['sub_prep_parts']['sales'][0]['quantity'])->toBe(480.0);
});

it('lists the transactions behind a raw material figure, through the Sub-Prep, adding up to the figure', function () {
    $f = subPrepMixFixture();
    subPrepMixMovements($f);
    $rows = subPrepMixReport($f);

    foreach (['RM-MATCHA', 'RM-SUGAR'] as $itemCode) {
        foreach (['beg_bal' => 'beg_bal_qty', 'sales' => 'sales_qty', 'wastage' => 'wastage_qty'] as $metric => $field) {
            expect(subPrepMixPopup($f, $itemCode, $metric)['total'])->toBe($rows[$itemCode][$field], "{$itemCode} {$metric}");
        }
    }

    $sales = subPrepMixPopup($f, 'RM-MATCHA', 'sales');
    expect(collect($sales['rows'])->pluck('details')->all())->each->toContain('through Sub-Prep Matcha Prep Mix (SP-0001)')
        ->and(collect($sales['rows'])->firstWhere('ref_no', 'OLD-1'))->toMatchArray(['quantity' => 30.0, 'uom' => 'Gm', 'converted' => 30.0]);

    // The September count: the sugar found loose, and the sugar inside the 2 Batch of mix.
    $count = collect(subPrepMixPopup($f, 'RM-SUGAR', 'beg_bal')['rows'])->sortBy('quantity')->values();
    expect($count)->toHaveCount(2)
        ->and($count[0])->toMatchArray(['quantity' => 800.0, 'converted' => 800.0])
        ->and($count[0]['details'])->toContain('through Sub-Prep Matcha Prep Mix (SP-0001)')
        ->and($count[1])->toMatchArray(['quantity' => 1000.0, 'converted' => 1000.0])
        ->and($count[1]['details'])->not->toContain('Sub-Prep');
});

it('adds a counted mix only to the raw materials counted with it: an uncounted one keeps its book', function () {
    $f = subPrepMixFixture();
    // October: 1 Batch of mix and 100 Gm of powder counted; the sugar was not counted.
    subPrepMixCount($f, $f['october'], 'SP-0001|Gm|Gm', 'Batch', 1);
    subPrepMixCount($f, $f['october'], 'RM-MATCHA|Gm|Gm', 'Gm', 100);

    $rows = subPrepMixReport($f);

    expect($rows['RM-MATCHA']['actual_mec'])->toBe(130.0)
        ->and($rows['RM-MATCHA']['sub_prep_parts']['actual_mec'][0]['quantity'])->toBe(30.0)
        ->and($rows['RM-SUGAR']['actual_mec'])->toBe(0.0)
        ->and($rows['RM-SUGAR']['sub_prep_parts'])->toBe([]);
});

it('keeps a mix quantity in a unit its SAP rows do not convert out of the figures, and names it', function () {
    $f = subPrepMixFixture();
    subPrepMixMovements($f);
    // 5 Pc of a mix kept by the Gm and the Batch converts into nothing (one count line per SAP row).
    subPrepMixCount($f, $f['september'], 'SP-0001|Gm|Batch', 'Pc', 5);

    $matcha = subPrepMixReport($f)['RM-MATCHA'];
    $popup = subPrepMixPopup($f, 'RM-MATCHA', 'beg_bal');

    expect($matcha['beg_bal_qty'])->toBe(110.0)
        ->and($matcha['unconverted_units'])->toBe(['PC of SP-0001'])
        ->and($popup['total'])->toBe(110.0)
        ->and($popup['unconverted_units'])->toBe(['PC of SP-0001']);
});

it('lists a raw material whose only movement is its mix, and never the mix, on the report page', function () {
    $f = subPrepMixFixture();
    // Only the mix moved: 430 Gm of it filed as sold, and 1 Batch counted (nothing else counted).
    $sale = DB::table('store_transactions')->insertGetId([
        'entity_id' => $f['entity']->id, 'store_branch_id' => $f['store']->id, 'order_date' => '2026-10-03', 'posted' => 'Y',
        'tim_number' => '1', 'receipt_number' => 'OLD-2', 'bom_frozen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('bom_consumptions')->insert([
        'entity_id' => $f['entity']->id, 'store_branch_id' => $f['store']->id, 'business_date' => '2026-10-03', 'source_type' => 'sale',
        'store_transaction_id' => $sale, 'pos_code' => 'TC-ICHIGO', 'item_code' => 'SP-0001', 'bom_uom' => 'Gm',
        'bom_qty' => '43.0000000', 'units' => '10.0000', 'qty' => '430.0000000000', 'origin' => 'posted', 'created_at' => now(), 'updated_at' => now(),
    ]);
    subPrepMixCount($f, $f['october'], 'SP-0001|Gm|Gm', 'Batch', 1);
    test()->actingAs($f['user']);

    $request = Request::create('/reports/inventory-movement', 'GET', ['branch_id' => $f['store']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'per_page' => 50]);
    $page = app(InventoryMovementReportController::class)->index($request)->toResponse($request)->getOriginalContent()->getData()['page'];
    $rows = collect($page['props']['movementData'])->keyBy('sap_code');

    expect($rows->keys()->sort()->values()->all())->toBe(['RM-MATCHA', 'RM-SUGAR'])
        ->and($rows['RM-MATCHA']['sales_qty'])->toBe(30.0)
        ->and($rows['RM-SUGAR']['sales_qty'])->toBe(400.0)
        // Neither was counted itself, so the count of the mix is not theirs.
        ->and($rows['RM-MATCHA']['actual_mec'])->toBe(0.0);
});

it('lists no raw material whose mix was only counted, when it was not counted itself', function () {
    $f = subPrepMixFixture();
    subPrepMixCount($f, $f['october'], 'SP-0001|Gm|Gm', 'Batch', 1);
    test()->actingAs($f['user']);

    $request = Request::create('/reports/inventory-movement', 'GET', ['branch_id' => $f['store']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'per_page' => 50]);
    $page = app(InventoryMovementReportController::class)->index($request)->toResponse($request)->getOriginalContent()->getData()['page'];

    expect($page['props']['movementData'])->toBe([]);
});

it('offers the mix in the wastage search as the Sub-Prep only, not as a SAP item of its own', function () {
    $f = subPrepMixFixture();

    $items = app(WastageController::class)
        ->getAvailableItems(Request::create('/wastage/items/search', 'GET', ['store_id' => $f['store']->id, 'search' => 'Matcha']))
        ->getData(true)['items'];
    $mix = collect($items)->where('item_code', 'SP-0001');

    expect($mix)->toHaveCount(1)
        ->and($mix->first()['sub_prep'] ?? false)->toBeTrue()
        ->and(collect($items)->pluck('item_code')->all())->toContain('RM-MATCHA');
});

it('leaves a counted Sub-Prep out of the Qty / Cost Variance report and counts it in its raw materials', function () {
    $f = subPrepMixFixture();
    subPrepMixMovements($f);

    $rows = app(MonthEndStockVariance::class)->rows($f['october'], [$f['store']->id])->keyBy('item_code');

    expect($rows->keys()->sort()->values()->all())->toBe(['RM-MATCHA', 'RM-SUGAR'])
        ->and($rows['RM-MATCHA']['actual_inventory'])->toBe(115.0)
        ->and($rows['RM-SUGAR']['actual_inventory'])->toBe(500.0);
});

/** Stock on hand of a SAP row at the store, from its stock history. */
function subPrepMixBalance(array $f, string $sapKey): float
{
    return round((float) app(\App\Services\MonthEndStockAdjustment::class)->balance($f['sap'][$sapKey], $f['store']->id), 4);
}

function subPrepMixStock(array $f, string $sapKey, string $action, float $qty, string $remarks = 'Received'): void
{
    \App\Models\ProductInventoryStockManager::create([
        'product_inventory_id' => $f['sap'][$sapKey], 'store_branch_id' => $f['store']->id, 'quantity' => $qty, 'action' => $action,
        'transaction_date' => '2026-10-01', 'unit_cost' => 0, 'total_cost' => 0, 'remarks' => $remarks,
    ]);
}

it('posts a counted mix at Level 2 onto the raw materials counted with it and leaves the mix at 0', function () {
    $f = subPrepMixFixture();
    // On the books: 500 Gm of powder, 2,000 Gm of sugar, and 430 Gm left on the mix's row from before.
    subPrepMixStock($f, 'RM-MATCHA|Gm|Gm', 'add', 500);
    subPrepMixStock($f, 'RM-SUGAR|Gm|Gm', 'add', 2000);
    subPrepMixStock($f, 'SP-0001|Gm|Gm', 'add', 430);

    // October: 1.0286 Batch of mix and 100 Gm of powder; the sugar was not counted.
    subPrepMixCount($f, $f['october'], 'SP-0001|Gm|Gm', 'Batch', 1.0286);
    subPrepMixCount($f, $f['october'], 'RM-MATCHA|Gm|Gm', 'Gm', 100);
    \App\Models\MonthEndCountItem::where('month_end_schedule_id', $f['october']->id)->update(['status' => 'level1_approved', 'level2_approved_at' => null]);

    Permission::findOrCreate('approve month end count level 2');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('approve month end count level 2');
    test()->actingAs($f['user']);

    $response = app(\App\Http\Controllers\MECApproval2Controller::class)->approveLevel2($f['october']->id, $f['store']->id);

    expect(session('errors')?->all() ?? [])->toBe([])
        ->and($response->getTargetUrl())->toContain('month-end-count-approvals-level2')
        // 100 Gm found loose, plus 1.0286 x 30 Gm inside the mix.
        ->and(subPrepMixBalance($f, 'RM-MATCHA|Gm|Gm'))->toBe(130.858)
        // Not counted: its book still holds what went into the mix.
        ->and(subPrepMixBalance($f, 'RM-SUGAR|Gm|Gm'))->toBe(2000.0)
        // The mix holds no stock.
        ->and(subPrepMixBalance($f, 'SP-0001|Gm|Gm'))->toBe(0.0)
        ->and(\App\Models\MonthEndCountItem::where('month_end_schedule_id', $f['october']->id)->pluck('status')->unique()->all())->toBe(['level2_approved']);

    $inside = DB::table('product_inventory_stock_managers')->where('product_inventory_id', $f['sap']['RM-MATCHA|Gm|Gm'])->where('remarks', 'like', '%SUBPREP_REF::%')->first();
    expect($inside->action)->toBe('add')
        ->and(round((float) $inside->quantity, 4))->toBe(30.858)
        ->and($inside->remarks)->toContain('SP-0001')
        // The month end repair tools look for these words; the part inside a mix must not read as a count.
        ->and(preg_match('/month\s*end|\bMEC\b/i', $inside->remarks))->toBe(0);
});

it('moves the stock an earlier Level 2 approval left on a mix, once, and only with --apply', function () {
    $f = subPrepMixFixture();
    // As production was approved on 2026-11-23: the mix row got the count, the powder its own count.
    subPrepMixStock($f, 'RM-SUGAR|Gm|Gm', 'add', 2000);
    subPrepMixCount($f, $f['october'], 'SP-0001|Gm|Gm', 'Batch', 1.0286);
    subPrepMixCount($f, $f['october'], 'RM-MATCHA|Gm|Gm', 'Gm', 100);
    $ref = "||MEC_REF::{$f['october']->id},{$f['store']->id}";
    subPrepMixStock($f, 'SP-0001|Gm|Gm', 'add', 442.298, 'Month End Count Approved for the month of October 2026; counted 442.298'.$ref);
    subPrepMixStock($f, 'RM-MATCHA|Gm|Gm', 'add', 100, 'Month End Count Approved for the month of October 2026; counted 100'.$ref);
    app(EntityContext::class)->clear();

    test()->artisan('stock:break-down-sub-preps')->assertSuccessful();
    app(EntityContext::class)->set($f['entity']->id);
    expect(subPrepMixBalance($f, 'SP-0001|Gm|Gm'))->toBe(442.298)
        ->and(subPrepMixBalance($f, 'RM-MATCHA|Gm|Gm'))->toBe(100.0);

    app(EntityContext::class)->clear();
    test()->artisan('stock:break-down-sub-preps', ['--apply' => true])->assertSuccessful();
    test()->artisan('stock:break-down-sub-preps', ['--apply' => true])->expectsOutputToContain('Nothing to move.')->assertSuccessful();
    app(EntityContext::class)->set($f['entity']->id);

    expect(subPrepMixBalance($f, 'SP-0001|Gm|Gm'))->toBe(0.0)
        ->and(subPrepMixBalance($f, 'RM-MATCHA|Gm|Gm'))->toBe(130.858)
        ->and(subPrepMixBalance($f, 'RM-SUGAR|Gm|Gm'))->toBe(2000.0)
        ->and(DB::table('product_inventory_stock_managers')->where('remarks', 'like', '%SUBPREP_REF::%')->count())->toBe(2)
        ->and(DB::table('product_inventory_stock_managers')->where('remarks', 'like', '%SUBPREP_REF::%')->pluck('transaction_date')
            ->map(fn ($date) => substr((string) $date, 0, 10))->unique()->all())->toBe(['2026-10-01']);
});
