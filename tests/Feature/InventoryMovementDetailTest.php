<?php

use App\Enums\WastageStatus;
use App\Exports\InventoryMovementDetailExport;
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
use App\Support\EntityContext;
use App\Support\ReportNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A click on a figure of the Inventory Movement Report lists the transactions behind it.
 * Those lines must add up to the figure, each in its own unit and converted like the
 * report, and each must carry the link that opens it.
 *
 * The services are called directly: every test HTTP request disconnects the database on
 * terminate(), which rolls back RefreshDatabase's transaction.
 */
afterEach(fn () => Carbon::setTestNow());

function movementDetailFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00', 'Asia/Manila'));

    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $store = StoreBranch::create(['branch_code' => 'TIMA', 'brand_code' => 'TIMA', 'name' => 'Main Store', 'store_status' => 'Active', 'is_active' => 1]);
    $other = StoreBranch::create(['branch_code' => 'TIMB', 'brand_code' => 'TIMB', 'name' => 'Other Store', 'store_status' => 'Active', 'is_active' => 1]);
    $user->store_branches()->attach($store->id);

    // Cocoa is kept by the Bag (1,000 Gm); gloves by the piece, and are supplies.
    $sap = [];
    foreach ([
        ['RM-COCOA', 'Powder - Cocoa', 'Gm', 'Bag', 1000, 1], ['RM-COCOA', 'Powder - Cocoa', 'Bag', 'Bag', 1, 1],
        ['RM-GLOVE', 'Gloves', 'Pc', 'Pc', 1, 1],
    ] as [$code, $description, $alt, $base, $altQty, $baseQty]) {
        $sap[$code.'|'.$alt] = SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $description,
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
    }

    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'TGI', 'name' => 'TGI Foods', 'is_active' => true]);
    DB::table('supplier_items')->insert([
        'entity_id' => $entity->id, 'ItemCode' => 'RM-GLOVE', 'SupplierCode' => 'TGI', 'category' => 'Supplies', 'uom' => 'Pc', 'is_active' => true,
    ]);

    $order = fn (string $number, string $date, string $status, array $extra = []) => DB::table('store_orders')->insertGetId(array_merge([
        'encoder_id' => $user->id, 'supplier_id' => $supplierId, 'store_branch_id' => $store->id,
        'order_number' => $number, 'order_date' => $date, 'order_status' => $status,
    ], $extra));
    $line = fn (int $orderId, string $code, string $uom, float $approved, float $committed) => DB::table('store_order_items')->insertGetId([
        'store_order_id' => $orderId, 'item_code' => $code, 'uom' => $uom,
        'quantity_ordered' => $approved, 'quantity_approved' => $approved, 'quantity_commited' => $committed, 'quantity_received' => 0,
        'cost_per_quantity' => 0, 'total_cost' => 0,
    ]);
    $receive = fn (int $lineId, float $qty, string $date, string $status = 'approved') => DB::table('ordered_item_receive_dates')->insert([
        'store_order_item_id' => $lineId, 'quantity_received' => $qty, 'received_date' => $date, 'status' => $status,
    ]);

    // An order of 3 Bag, 2 committed, received in two deliveries; and one outside the period.
    $orderId = $order('ORD-1', '2026-10-05', 'received');
    $cocoaLine = $line($orderId, 'RM-COCOA', 'Bag', 3, 2);
    $receive($cocoaLine, 1.5, '2026-10-06 09:00:00');
    $receive($cocoaLine, 0.5, '2026-10-08 09:00:00');
    $receive($cocoaLine, 9, '2026-10-08 10:00:00', 'pending');
    $receive($line($orderId, 'RM-GLOVE', 'Pc', 100, 100), 100, '2026-10-06 09:00:00');
    $line($order('ORD-0', '2026-09-20', 'received'), 'RM-COCOA', 'Bag', 7, 7);

    // 500 Gm came in from the other store; 1 Bag went out to it.
    $receive($line($order('ICO-IN', '2026-10-06', 'received', ['interco_number' => 'IC-IN-1', 'sending_store_branch_id' => $other->id]), 'RM-COCOA', 'Gm', 500, 500), 500, '2026-10-07 09:00:00');
    $outId = $order('ICO-OUT', '2026-10-09', 'committed', ['interco_number' => 'IC-OUT-1', 'sending_store_branch_id' => $store->id, 'store_branch_id' => $other->id]);
    $line($outId, 'RM-COCOA', 'Bag', 1, 1);

    // A drink uses 10 Gm of cocoa: 4 sold on one receipt, 1 on another.
    $drink = POSMasterfile::create(['POSCode' => 'FG-1', 'POSDescription' => 'Mocha Latte', 'SRP' => 180, 'is_active' => true]);
    POSMasterfileBOM::create(['POSCode' => 'FG-1', 'ItemCode' => 'RM-COCOA', 'BOMQty' => 10, 'BOMUOM' => 'Gm']);
    $receipts = [];
    foreach ([['R-100', '2026-10-07', 4], ['R-101', '2026-10-08', 1]] as [$receipt, $date, $qty]) {
        $receipts[$receipt] = DB::table('store_transactions')->insertGetId([
            'store_branch_id' => $store->id, 'order_date' => $date, 'posted' => 'Y', 'tim_number' => 'TIM-1', 'receipt_number' => $receipt,
        ]);
        DB::table('store_transaction_items')->insert([
            'store_transaction_id' => $receipts[$receipt], 'product_id' => $drink->id, 'base_quantity' => $qty, 'quantity' => $qty,
            'price' => 180, 'discount' => 0, 'line_total' => 180 * $qty, 'net_total' => 180 * $qty,
        ]);
    }

    // 40 Gm wasted as cocoa, and 5 ml of a Sub-Prep that uses 2 Gm of it per ml.
    $subPrep = POSMasterfile::create(['POSCode' => 'SP-1', 'POSDescription' => 'Chocolate Mix', 'Category' => 'Sub-Prep', 'UOM' => 'ml', 'SRP' => 0, 'is_active' => true]);
    POSMasterfileBOM::create(['POSCode' => 'SP-1', 'ItemCode' => 'RM-COCOA', 'BOMQty' => 2, 'BOMUOM' => 'Gm']);
    $waste = fn (string $no, array $item, float $qty, string $status = 'approved_lvl2') => Wastage::create($item + [
        'store_branch_id' => $store->id, 'wastage_no' => $no, 'wastage_qty' => $qty, 'approverlvl2_qty' => $qty, 'cost' => 1,
        'reason' => 'Spoilage', 'wastage_status' => $status, 'created_by' => $user->id,
    ]);
    $waste('WS-1', ['sap_masterfile_id' => $sap['RM-COCOA|Gm']], 40);
    $waste('WS-2', ['pos_masterfile_id' => $subPrep->id], 5);
    $waste('WS-3', ['sap_masterfile_id' => $sap['RM-COCOA|Gm']], 999, WastageStatus::PENDING->value);

    // September's count is October's beginning balance; October's is the actual count.
    $september = MonthEndSchedule::create(['year' => 2026, 'month' => 9, 'calculated_date' => '2026-09-30', 'created_by' => $user->id]);
    $october = MonthEndSchedule::create(['year' => 2026, 'month' => 10, 'calculated_date' => '2026-10-31', 'created_by' => $user->id]);
    foreach ([
        [$september, 'RM-COCOA|Bag', 'RM-COCOA', 'Bag', 4], [$september, 'RM-GLOVE|Pc', 'RM-GLOVE', 'Pc', 50],
        [$october, 'RM-COCOA|Bag', 'RM-COCOA', 'Bag', 3], [$october, 'RM-GLOVE|Pc', 'RM-GLOVE', 'Pc', 120],
    ] as [$schedule, $key, $code, $uom, $qty]) {
        DB::table('month_end_count_items')->insert([
            'entity_id' => $entity->id, 'month_end_schedule_id' => $schedule->id, 'branch_id' => $store->id, 'sap_masterfile_id' => $sap[$key],
            'item_code' => $code, 'item_name' => $code, 'uom' => $uom, 'total_qty' => $qty, 'status' => 'level2_approved', 'created_by' => $user->id,
        ]);
    }

    return compact('entity', 'user', 'store', 'other', 'sap', 'september', 'october', 'receipts', 'outId');
}

function movementDetail(array $f, string $code, string $metric, int $page = 1, ?int $perPage = InventoryMovementDetailService::PER_PAGE): array
{
    return app(InventoryMovementDetailService::class)->details(
        SAPMasterfile::where('is_active', true)->where('ItemCode', $code)->orderBy('id')->get(),
        $f['store']->id, ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $metric, $page, $perPage
    );
}

/** The popup's Excel export of one figure, written to a real file and read back. */
function movementDetailSheet(array $f, string $code, string $metric): array
{
    $path = tempnam(sys_get_temp_dir(), 'imd').'.xlsx';

    file_put_contents($path, Excel::raw(new InventoryMovementDetailExport(
        movementDetail($f, $code, $metric, 1, null), $metric, $code, SAPMasterfile::where('ItemCode', $code)->value('ItemDescription'),
        $f['store'], ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], 'QA Tester', '2026-10-10 10:00:00'
    ), \Maatwebsite\Excel\Excel::XLSX));

    return [IOFactory::load($path)->getActiveSheet(), $path];
}

function movementReportRow(array $f, string $code): array
{
    return collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::where('is_active', true)->where('ItemCode', $code)->get(),
        ['branch_id' => $f['store']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']
    ))->firstWhere('sap_code', $code);
}

it('adds up, for every clickable column, to the figure the report shows', function () {
    $f = movementDetailFixture();
    $cocoa = movementReportRow($f, 'RM-COCOA');
    $gloves = movementReportRow($f, 'RM-GLOVE');

    // The report's own figures, so the comparison below is not a tautology.
    expect([$cocoa['ordered_qty'], $cocoa['committed_qty'], $cocoa['received_qty'], $cocoa['beg_bal_qty'], $cocoa['sales_qty'], $cocoa['wastage_qty'], $cocoa['interco_in_qty'], $cocoa['interco_out_qty']])
        ->toBe([3.0, 2.0, 2.0, 4.0, 0.05, 0.05, 0.5, 1.0])
        ->and($gloves['supplies_qty'])->toBe(30.0);

    foreach ([
        'ordered' => 'ordered_qty', 'committed' => 'committed_qty', 'received' => 'received_qty', 'beg_bal' => 'beg_bal_qty',
        'sales' => 'sales_qty', 'wastage' => 'wastage_qty', 'interco_in' => 'interco_in_qty', 'interco_out' => 'interco_out_qty',
    ] as $metric => $field) {
        $detail = movementDetail($f, 'RM-COCOA', $metric);

        expect([$metric, $detail['total'], $detail['uom']])->toBe([$metric, $cocoa[$field], 'Bag'])
            ->and([$metric, round(array_sum(array_column($detail['rows'], 'converted')), 6)])->toBe([$metric, $cocoa[$field]]);
    }

    expect(movementDetail($f, 'RM-GLOVE', 'supplies')['total'])->toBe($gloves['supplies_qty']);
});

it('lists each transaction with its own quantity and the link that opens it', function () {
    $f = movementDetailFixture();
    $lines = fn (string $metric) => array_map(
        fn ($row) => [$row['date'], $row['ref_no'], $row['details'], $row['quantity'], $row['uom'], $row['converted'], $row['ref_url']],
        movementDetail($f, 'RM-COCOA', $metric)['rows']
    );

    expect($lines('ordered'))->toBe([
        ['Oct 5, 2026', 'ORD-1', 'TGI Foods · Received', 3.0, 'Bag', 3.0, route('store-orders.show', 'ORD-1')],
    ])->and($lines('committed'))->toBe([
        ['Oct 5, 2026', 'ORD-1', 'TGI Foods · Received', 2.0, 'Bag', 2.0, route('store-orders.show', 'ORD-1')],
    ])->and($lines('received'))->toBe([
        // Two approved deliveries of the same order; the pending one is not stock yet.
        ['Oct 5, 2026', 'ORD-1', 'TGI Foods · received Oct 6, 2026', 1.5, 'Bag', 1.5, route('orders-receiving.show', 'ORD-1')],
        ['Oct 5, 2026', 'ORD-1', 'TGI Foods · received Oct 8, 2026', 0.5, 'Bag', 0.5, route('orders-receiving.show', 'ORD-1')],
    ])->and($lines('interco_in'))->toBe([
        ['Oct 6, 2026', 'IC-IN-1', 'From Other Store · received Oct 7, 2026', 500.0, 'Gm', 0.5, route('interco-receiving.show', 'IC-IN-1')],
    ])->and($lines('interco_out'))->toBe([
        ['Oct 9, 2026', 'IC-OUT-1', 'To Other Store · Committed', 1.0, 'Bag', 1.0, route('interco.show', $f['outId'])],
    ])->and($lines('sales'))->toBe([
        ['Oct 8, 2026', 'R-101', 'Mocha Latte (FG-1)', 10.0, 'Gm', 0.01, route('store-transactions.show', $f['receipts']['R-101'])],
        ['Oct 7, 2026', 'R-100', 'Mocha Latte (FG-1)', 40.0, 'Gm', 0.04, route('store-transactions.show', $f['receipts']['R-100'])],
    ])->and($lines('wastage'))->toBe([
        // The Sub-Prep line says so: 5 ml x 2 Gm.
        ['Oct 10, 2026', 'WS-2', 'Sub-Prep SP-1 Chocolate Mix · Spoilage', 10.0, 'Gm', 0.01, route('wastage.show.by-number', 'WS-2')],
        ['Oct 10, 2026', 'WS-1', 'Spoilage', 40.0, 'Gm', 0.04, route('wastage.show.by-number', 'WS-1')],
    ])->and($lines('beg_bal'))->toBe([
        ['Sep 30, 2026', 'MEC September 2026', 'Month end count · Level2 Approved', 4.0, 'Bag', 4.0, route('month-end-count-approvals.show', [$f['september']->id, $f['store']->id])],
    ]);
});

it('opens an order on the page it was placed from', function () {
    $f = movementDetailFixture();
    $supplierId = DB::table('suppliers')->where('supplier_code', 'TGI')->value('id');

    foreach ([['MO-1', 'mass regular', null], ['DTS-1', 'mass dts', 'MDTS-20261009-001'], ['SO-1', 'regular', null]] as [$number, $variant, $batch]) {
        $orderId = DB::table('store_orders')->insertGetId([
            'encoder_id' => $f['user']->id, 'supplier_id' => $supplierId, 'store_branch_id' => $f['store']->id,
            'order_number' => $number, 'order_date' => '2026-10-09', 'order_status' => 'approved', 'variant' => $variant, 'batch_reference' => $batch,
        ]);
        DB::table('store_order_items')->insert([
            'store_order_id' => $orderId, 'item_code' => 'RM-COCOA', 'uom' => 'Bag',
            'quantity_ordered' => 1, 'quantity_approved' => 1, 'quantity_commited' => 0, 'quantity_received' => 0, 'cost_per_quantity' => 0, 'total_cost' => 0,
        ]);
    }

    $links = collect(movementDetail($f, 'RM-COCOA', 'ordered')['rows'])->pluck('ref_url', 'ref_no');

    expect($links['MO-1'])->toBe(route('mass-orders.show', 'MO-1'))
        ->and($links['DTS-1'])->toBe(route('dts-mass-orders.show', 'MDTS-20261009-001'))
        ->and($links['SO-1'])->toBe(route('store-orders.show', 'SO-1'));
});

it('shows how Supplies Used was worked out, with the count as its reference', function () {
    $f = movementDetailFixture();

    $detail = movementDetail($f, 'RM-GLOVE', 'supplies');

    // 50 at the start + 100 received = 150 on the books; 120 counted, so 30 were used.
    expect(array_map(fn ($line) => [$line['sign'], $line['label'], $line['value']], $detail['calculation']))->toBe([
        ['', 'Beg Bal Qty', 50.0], ['+', 'Received', 100.0], ['+', 'Inbound Interco', 0.0], ['-', 'Sales Qty', 0.0],
        ['-', 'Wastage Qty', 0.0], ['-', 'Outbound Interco', 0.0], ['=', 'Stock the books expect', 150.0],
        ['-', 'Actual MEC', 120.0], ['=', 'Supplies Used', 30.0],
    ])->and($detail['rows'])->toBe([[
        'date' => 'Oct 31, 2026', 'ref_no' => 'MEC October 2026',
        'ref_url' => route('month-end-count-approvals.show', [$f['october']->id, $f['store']->id]),
        'details' => 'Not found by the month end count', 'quantity' => 30.0, 'uom' => 'Pc', 'converted' => 30.0,
    ]]);

    // Cocoa is not a supplies item: there is nothing to work out.
    $cocoa = movementDetail($f, 'RM-COCOA', 'supplies');

    expect($cocoa['rows'])->toBe([])
        ->and($cocoa['calculation'])->toBe([])
        ->and($cocoa['note'])->toContain('applies only to');
});

it('pages a long history and keeps the total of all of it', function () {
    $f = movementDetailFixture();
    $drinkId = POSMasterfile::where('POSCode', 'FG-1')->value('id');

    foreach (range(1, 30) as $n) {
        $transactionId = DB::table('store_transactions')->insertGetId([
            'store_branch_id' => $f['store']->id, 'order_date' => '2026-10-12', 'posted' => 'Y', 'tim_number' => 'TIM-1', 'receipt_number' => sprintf('P-%03d', $n),
        ]);
        DB::table('store_transaction_items')->insert([
            'store_transaction_id' => $transactionId, 'product_id' => $drinkId, 'base_quantity' => 1, 'quantity' => 1,
            'price' => 180, 'discount' => 0, 'line_total' => 180, 'net_total' => 180,
        ]);
    }

    $first = movementDetail($f, 'RM-COCOA', 'sales');
    $second = movementDetail($f, 'RM-COCOA', 'sales', 2);

    // 32 receipts: 50 Gm from before and 30 x 10 Gm = 350 Gm = 0.35 Bag.
    expect([$first['total_rows'], $first['total'], count($first['rows']), $first['rows'][0]['ref_no']])->toBe([32, 0.35, 25, 'P-030'])
        ->and([$second['total'], count($second['rows']), $second['rows'][6]['ref_no']])->toBe([0.35, 7, 'R-100'])
        ->and($first['total'])->toBe(movementReportRow($f, 'RM-COCOA')['sales_qty']);

    // The Excel export of the popup takes every line at once, in the same order.
    $all = movementDetail($f, 'RM-COCOA', 'sales', 1, null);

    expect([count($all['rows']), $all['total'], $all['rows'][0]['ref_no'], $all['rows'][31]['ref_no']])->toBe([32, 0.35, 'P-030', 'R-100']);
});

it('exports the popup to Excel with its lines as a date and numbers, each Ref No. linked', function () {
    $f = movementDetailFixture();
    [$sheet, $path] = movementDetailSheet($f, 'RM-COCOA', 'wastage');
    $cell = fn (string $at) => $sheet->getCell($at)->getValue();

    expect($cell('A1'))->toBe('Wastage Qty: RM-COCOA Powder - Cocoa')
        ->and($cell('A2'))->toBe('Main Store (TIMA)  |  Oct 1, 2026 to Oct 31, 2026')
        ->and($cell('A3'))->toBe('Total: 0.0500 Bag  |  Generated: 2026-10-10 10:00:00  |  By: QA Tester')
        ->and(array_map(fn ($column) => $cell($column.'4'), range('A', 'F')))->toBe(['Date Filed', 'Ref No.', 'Details', 'Qty', 'UOM', 'In Bag']);

    // The popup's lines in the popup's order; the Sub-Prep line still says so.
    expect(Date::excelToDateTimeObject($cell('A5'))->format('Y-m-d'))->toBe('2026-10-10')
        ->and([$cell('B5'), $cell('C5'), $cell('E5')])->toBe(['WS-2', 'Sub-Prep SP-1 Chocolate Mix · Spoilage', 'Gm'])
        ->and([$cell('D5'), $cell('F5')])->toEqual([10, 0.01])
        ->and($sheet->getCell('B5')->getHyperlink()->getUrl())->toBe(route('wastage.show.by-number', 'WS-2'))
        ->and([$cell('B6'), $cell('D6'), $cell('F6')])->toEqual(['WS-1', 40, 0.04])
        ->and($sheet->getStyle('D5')->getNumberFormat()->getFormatCode())->toBe(ReportNumber::EXCEL)
        ->and($sheet->getStyle('F6')->getNumberFormat()->getFormatCode())->toBe(ReportNumber::EXCEL)
        // The figure that was clicked closes the list.
        ->and([$cell('A7'), $cell('F7')])->toEqual(['Total of all 2 lines', 0.05]);

    unlink($path);
});

it('exports how Supplies Used was worked out under its one line', function () {
    $f = movementDetailFixture();
    [$sheet, $path] = movementDetailSheet($f, 'RM-GLOVE', 'supplies');
    $cell = fn (string $at) => $sheet->getCell($at)->getValue();

    expect($cell('A1'))->toBe('Supplies Used: RM-GLOVE Gloves')
        ->and([$cell('B5'), $cell('C5'), $cell('F5')])->toEqual(['MEC October 2026', 'Not found by the month end count', 30])
        ->and($cell('A6'))->toBe('Total of all 1 line')
        ->and($cell('A8'))->toContain('Supplies are used up without a transaction')
        ->and($cell('A10'))->toBe('How Supplies Used was worked out (Pc)')
        ->and([$cell('B11'), $cell('F11')])->toEqual(['Beg Bal Qty', 50])
        ->and([$cell('A17'), $cell('B17'), $cell('F17')])->toEqual(['=', 'Stock the books expect', 150])
        ->and([$cell('A19'), $cell('B19'), $cell('F19')])->toEqual(['=', 'Supplies Used', 30]);

    unlink($path);
});

it('downloads the popup as an Excel file, only for a store the user is assigned to', function (string $storeKey, int $status) {
    $f = movementDetailFixture();
    Permission::findOrCreate('view inventory movement report');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view inventory movement report');
    Excel::fake();

    $this->actingAs($f['user'])->get(route('reports.inventory-movement.details.export-excel', [
        'branch_id' => $f[$storeKey]->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'sap_code' => 'RM-COCOA', 'metric' => 'wastage',
    ]))->assertStatus($status);

    if ($status === 200) {
        Excel::assertDownloaded(
            'inventory-movement-wastage-qty-rm-cocoa-tima-2026-10-01-to-2026-10-31.xlsx',
            fn (InventoryMovementDetailExport $export) => count($export->array()) === 2
        );
    }
})->with([
    'own store' => ['store', 200],
    'another store' => ['other', 403],
]);

it('opens only for a store the user is assigned to', function (string $storeKey, int $status) {
    $f = movementDetailFixture();
    Permission::findOrCreate('view inventory movement report');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view inventory movement report');

    $this->actingAs($f['user'])->getJson(route('reports.inventory-movement.details', [
        'branch_id' => $f[$storeKey]->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'sap_code' => 'RM-COCOA', 'metric' => 'wastage',
    ]))->assertStatus($status);
})->with([
    'own store' => ['store', 200],
    'another store' => ['other', 403],
]);

it('refuses a column that is not one of the nine', function () {
    $f = movementDetailFixture();
    Permission::findOrCreate('view inventory movement report');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $f['user']->givePermissionTo('view inventory movement report');

    $this->actingAs($f['user'])->getJson(route('reports.inventory-movement.details', [
        'branch_id' => $f['store']->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'sap_code' => 'RM-COCOA', 'metric' => 'theoretical',
    ]))->assertStatus(422)->assertJsonValidationErrors('metric');
});
