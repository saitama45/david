<?php

use App\Http\Controllers\QtyVarianceCostVarianceReportController;
use App\Http\Services\InventoryMovementService;
use App\Models\Entity;
use App\Models\MonthEndSchedule;
use App\Models\SAPMasterfile;
use App\Models\SapItemType;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Services\MonthEndStockVariance;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// The controller is called directly: every test HTTP request disconnects the database on
// terminate(), which rolls back RefreshDatabase's transaction.

afterEach(fn () => Carbon::setTestNow());

/**
 * October's count, scheduled on October 3, for two stores.
 *
 * Store A: a lid and a cup (operating supplies) the count explains in full, and condensed
 * milk counted on two lines (Case and Can) and priced by the Can. Store B counted milk only.
 */
function qtyVarianceFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-04 10:00', 'Asia/Manila'));

    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $stores = collect(['A' => 'Glorietta Test', 'B' => 'Other Test'])->map(fn ($name, $code) => StoreBranch::create([
        'branch_code' => 'QV'.$code, 'brand_code' => 'QV'.$code, 'name' => $name, 'store_status' => 'Active', 'is_active' => 1,
    ]));
    foreach ($stores as $store) {
        UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);
    }

    $sap = [];
    foreach ([
        ['RM-LID', '4oz lid', 'Sleeve', 'Sleeve', 1, 1],
        ['RM-CUP', '8oz cup', 'Pc', 'Sleeve', 25, 1],
        ['RM-CUP', '8oz cup', 'Sleeve', 'Sleeve', 1, 1],
        // SAP restates the milk in two bases; 48 Can = 1 Case makes Case the stock unit.
        ['RM-MILK', 'Condensed Milk', 'Can', 'Can', 1, 1],
        ['RM-MILK', 'Condensed Milk', 'Can', 'Case', 48, 1],
        ['RM-MILK', 'Condensed Milk', 'Case', 'Case', 1, 1],
    ] as [$code, $name, $alt, $base, $altQty, $baseQty]) {
        $sap["{$code}|{$alt}|{$base}"] = SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $name, 'AltUOM' => $alt, 'BaseUOM' => $base,
            'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
    }

    $supplies = SapItemType::create(['name' => 'OPERATING SUPPLIES', 'is_active' => true])->id;
    $food = SapItemType::create(['name' => 'FOOD', 'is_active' => true])->id;
    foreach (['RM-LID' => $supplies, 'RM-CUP' => $supplies, 'RM-MILK' => $food] as $code => $type) {
        DB::table('sap_item_type_assignments')->insert(['entity_id' => $entity->id, 'item_code' => $code, 'sap_item_type_id' => $type]);
    }

    // The milk is priced by the Can only, the cup by the Sleeve, the lid by nobody.
    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'QVS', 'name' => 'Supplier', 'is_active' => true]);
    foreach ([['RM-MILK', 'Can', 37.5], ['RM-CUP', 'Sleeve', 2.8]] as [$code, $unit, $cost]) {
        DB::table('supplier_items')->insert([
            'entity_id' => $entity->id, 'ItemCode' => $code, 'SupplierCode' => 'QVS', 'category' => '',
            'uom' => $unit, 'cost' => $cost, 'is_active' => true,
        ]);
    }

    // Received on October 2, inside the period; the 5 Cases of October 4 arrive after the count.
    foreach ([['2026-10-02', ['RM-LID' => ['Sleeve', 1], 'RM-CUP' => ['Sleeve', 1], 'RM-MILK' => ['Case', 1]]], ['2026-10-04', ['RM-MILK' => ['Case', 5]]]] as $n => [$date, $lines]) {
        $orderId = DB::table('store_orders')->insertGetId([
            'encoder_id' => $user->id, 'supplier_id' => $supplierId, 'store_branch_id' => $stores['A']->id,
            'order_number' => 'QV-'.$n, 'order_date' => $date, 'order_status' => 'received',
        ]);
        foreach ($lines as $code => [$unit, $qty]) {
            $itemId = DB::table('store_order_items')->insertGetId([
                'store_order_id' => $orderId, 'item_code' => $code, 'uom' => $unit,
                'quantity_ordered' => $qty, 'quantity_approved' => $qty, 'quantity_commited' => $qty, 'quantity_received' => $qty,
                'cost_per_quantity' => 0, 'total_cost' => 0,
            ]);
            DB::table('ordered_item_receive_dates')->insert(['store_order_item_id' => $itemId, 'quantity_received' => $qty, 'status' => 'approved']);
        }
    }

    $september = MonthEndSchedule::create(['year' => 2026, 'month' => 9, 'calculated_date' => '2026-09-30', 'created_by' => $user->id]);
    $october = MonthEndSchedule::create(['year' => 2026, 'month' => 10, 'calculated_date' => '2026-10-03', 'created_by' => $user->id]);

    foreach ([
        // September closes with 1 Sleeve of lids, 15 cups (0.6 Sleeve) and 2 Cases of milk.
        [$september, 'A', 'RM-LID|Sleeve|Sleeve', 'Sleeve', 1],
        [$september, 'A', 'RM-CUP|Pc|Sleeve', 'Pc', 15],
        [$september, 'A', 'RM-MILK|Case|Case', 'Case', 2],
        // October counts 1 Sleeve of each, and the milk on two lines: 1 Case + 24 Can = 1.5 Case.
        [$october, 'A', 'RM-LID|Sleeve|Sleeve', 'Sleeve', 1],
        [$october, 'A', 'RM-CUP|Sleeve|Sleeve', 'Sleeve', 1],
        [$october, 'A', 'RM-MILK|Case|Case', 'Case', 1],
        [$october, 'A', 'RM-MILK|Can|Can', 'Can', 24],
        [$october, 'B', 'RM-MILK|Case|Case', 'Case', 4],
    ] as [$schedule, $store, $row, $unit, $qty]) {
        DB::table('month_end_count_items')->insert([
            'entity_id' => $entity->id, 'month_end_schedule_id' => $schedule->id, 'branch_id' => $stores[$store]->id,
            'sap_masterfile_id' => $sap[$row], 'item_code' => explode('|', $row)[0], 'item_name' => explode('|', $row)[0],
            'created_by' => $user->id, 'status' => 'level2_approved', 'level2_approved_at' => '2026-10-03 18:00:00',
            'total_qty' => $qty, 'uom' => $unit,
        ]);
    }

    return ['user' => $user, 'stores' => $stores, 'october' => $october];
}

/** The report page's rows, keyed "store code|item code". */
function qtyVarianceRows(array $f, array $query = []): array
{
    test()->actingAs($f['user']);
    $request = Request::create('/reports/qty-variance-cost-variance-report', 'GET', $query + ['mec_date' => '2026-10-03'], [], [], ['HTTP_X_INERTIA' => 'true']);
    $props = app(QtyVarianceCostVarianceReportController::class)->index($request)->toResponse($request)->getData(true)['props'];

    return [
        collect($props['varianceData'])->keyBy(fn ($row) => (str_contains($row['store_name'], 'QVA') ? 'A' : 'B').'|'.$row['item_code'])->all(),
        $props,
    ];
}

it('reads a count over the 1st of its month through its MEC Scheduled Date, inside that month', function () {
    $f = qtyVarianceFixture();
    $variance = app(MonthEndStockVariance::class);

    expect($variance->period($f['october']))->toBe(['2026-10-01', '2026-10-03']);

    // March's count, scheduled on April 5, is still March's movements.
    $march = new MonthEndSchedule(['year' => 2026, 'month' => 3, 'calculated_date' => '2026-04-05']);
    expect($variance->period($march))->toBe(['2026-03-01', '2026-03-31']);
});

it('shows the Inventory Movement Report figures of the count, in the base unit', function () {
    $f = qtyVarianceFixture();
    [$rows, $props] = qtyVarianceRows($f);

    expect($rows)->toHaveCount(4)
        ->and($props['period'])->toBe(['from' => 'Oct 1, 2026', 'to' => 'Oct 3, 2026']);

    // Supplies: 1 on hand + 1 received, 1 counted. The 1 used is Supplies Used, not a shortage.
    expect($rows['A|RM-LID']['uom'])->toBe('Sleeve')
        ->and((float) $rows['A|RM-LID']['actual_inventory'])->toBe(1.0)
        ->and((float) $rows['A|RM-LID']['theoretical_inventory'])->toBe(1.0)
        ->and((float) $rows['A|RM-LID']['qty_variance'])->toBe(0.0)
        ->and((float) $rows['A|RM-LID']['cost'])->toBe(0.0);

    // 15 Pc (0.6 Sleeve) + 1 Sleeve received, 1 Sleeve counted.
    expect((float) $rows['A|RM-CUP']['theoretical_inventory'])->toBe(1.0)
        ->and((float) $rows['A|RM-CUP']['qty_variance'])->toBe(0.0)
        ->and((float) $rows['A|RM-CUP']['cost'])->toBe(2.8)
        ->and((float) $rows['A|RM-CUP']['actual_cost'])->toBe(2.8)
        ->and((float) $rows['A|RM-CUP']['cost_variance'])->toBe(0.0);

    // One row for the two count lines: 1 Case + 24 Can = 1.5 Case against 2 + 1 received.
    // The 5 Cases received after the count are not in it. 37.50 a Can is 1,800 a Case.
    expect($rows['A|RM-MILK']['uom'])->toBe('Case')
        ->and((float) $rows['A|RM-MILK']['actual_inventory'])->toBe(1.5)
        ->and((float) $rows['A|RM-MILK']['theoretical_inventory'])->toBe(3.0)
        ->and((float) $rows['A|RM-MILK']['qty_variance'])->toBe(-1.5)
        ->and((float) $rows['A|RM-MILK']['cost'])->toBe(1800.0)
        ->and((float) $rows['A|RM-MILK']['actual_cost'])->toBe(2700.0)
        ->and((float) $rows['A|RM-MILK']['theoretical_cost'])->toBe(5400.0)
        ->and((float) $rows['A|RM-MILK']['cost_variance'])->toBe(-2700.0);

    // Store B has its own figures: nothing on the books, 4 Cases found.
    expect((float) $rows['B|RM-MILK']['actual_inventory'])->toBe(4.0)
        ->and((float) $rows['B|RM-MILK']['theoretical_inventory'])->toBe(0.0)
        ->and((float) $rows['B|RM-MILK']['qty_variance'])->toBe(4.0)
        ->and((float) $rows['B|RM-MILK']['actual_cost'])->toBe(7200.0);

    // Line for line the Inventory Movement Report of the same store and dates. 200 more
    // items take its list past the size sent to SQL Server, so its queries read every item
    // while the report page's (3 items) were narrowed - both must give the same figures.
    DB::table('sap_masterfiles')->insert(collect(range(1, 200))->map(fn ($n) => [
        'entity_id' => app(EntityContext::class)->id(), 'ItemCode' => 'FILL-'.$n, 'ItemDescription' => 'Filler',
        'AltUOM' => 'Pc', 'BaseUOM' => 'Pc', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
    ])->all());

    $movement = collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::where('is_active', true)->get(),
        ['branch_id' => $f['stores']['A']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-03']
    ))->keyBy('sap_code');

    foreach (['RM-LID', 'RM-CUP', 'RM-MILK'] as $code) {
        expect($rows['A|'.$code]['uom'])->toBe($movement[$code]['uom'])
            ->and((float) $rows['A|'.$code]['actual_inventory'])->toBe((float) $movement[$code]['actual_mec'])
            ->and((float) $rows['A|'.$code]['theoretical_inventory'])->toBe((float) $movement[$code]['theoretical_qty'])
            ->and((float) $rows['A|'.$code]['qty_variance'])->toBe((float) $movement[$code]['variance_qty']);
    }
});

it('filters the computed rows and explains a row with the report\'s own columns', function () {
    $f = qtyVarianceFixture();

    [$rows] = qtyVarianceRows($f, ['filter_uom' => 'case', 'filter_store' => 'glorietta']);
    expect(array_keys($rows))->toBe(['A|RM-MILK']);

    [$rows] = qtyVarianceRows($f, ['search' => 'cup', 'store_ids' => [$f['stores']['A']->id]]);
    expect(array_keys($rows))->toBe(['A|RM-CUP']);

    $breakdown = app(QtyVarianceCostVarianceReportController::class)->getBreakdown($rows['A|RM-CUP']['id'])->getData(true);

    expect($breakdown['period_from'])->toBe('Oct 1, 2026')
        ->and($breakdown['period_to'])->toBe('Oct 3, 2026')
        ->and($breakdown['uom'])->toBe('Sleeve')
        ->and($breakdown['supplies_type'])->toBe('Operating Supplies')
        ->and((float) $breakdown['beg_bal'])->toBe(0.6)
        ->and((float) $breakdown['received'])->toBe(1.0)
        ->and((float) $breakdown['supplies'])->toBe(0.6)
        ->and((float) $breakdown['theoretical'])->toBe(1.0)
        ->and((float) $breakdown['actual_mec'])->toBe(1.0);
});
