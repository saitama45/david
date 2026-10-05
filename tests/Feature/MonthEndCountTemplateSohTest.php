<?php

use App\Exports\MonthEndCountDownloadExport;
use App\Http\Controllers\MonthEndCountController;
use App\Http\Services\InventoryMovementService;
use App\Http\Services\MonthEndCountReadinessService;
use App\Models\Entity;
use App\Models\MonthEndCountTemplate;
use App\Models\MonthEndSchedule;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Controllers and services are called directly: every test HTTP request disconnects
// the database on terminate(), which rolls back RefreshDatabase's transaction.

afterEach(fn () => Carbon::setTestNow());

function mecSohFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-28 10:00', 'Asia/Manila'));

    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $stores = collect(['A' => 'Store A', 'B' => 'Store B', 'C' => 'Store C'])->map(fn ($name, $code) => StoreBranch::create([
        'branch_code' => 'T'.$code, 'brand_code' => 'T'.$code, 'name' => $name, 'store_status' => 'Active', 'is_active' => 1,
    ]));
    foreach ($stores as $store) {
        UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);
    }

    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'TGIT', 'name' => 'TGI Test', 'is_active' => true]);
    $august = MonthEndSchedule::create(['year' => 2026, 'month' => 8, 'calculated_date' => '2026-08-31', 'created_by' => $user->id]);

    return ['user' => $user, 'stores' => $stores, 'supplierId' => $supplierId, 'august' => $august];
}

function mecSohOrder(array $f, StoreBranch $store, string $date, string $status, array $extra = []): int
{
    static $n = 0;
    $n++;

    return DB::table('store_orders')->insertGetId(array_merge([
        'encoder_id' => $f['user']->id, 'supplier_id' => $f['supplierId'], 'store_branch_id' => $store->id,
        'order_number' => 'T-'.$n, 'order_date' => $date, 'order_status' => $status,
    ], $extra));
}

function mecSohWastage(StoreBranch $store, User $user, string $no, string $status, string $createdAt, ?int $sapId = null, float $qty = 1): void
{
    DB::table('wastages')->insert([
        'store_branch_id' => $store->id, 'wastage_no' => $no, 'sap_masterfile_id' => $sapId,
        'wastage_qty' => $qty, 'approverlvl2_qty' => $qty, 'cost' => 0, 'reason' => 'Test',
        'wastage_status' => $status, 'created_by' => $user->id, 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);
}

function mecSohMecCount(array $f, StoreBranch $store, int $sapId, string $status, float $qty = 1): void
{
    DB::table('month_end_count_items')->insert([
        'entity_id' => $f['august']->entity_id, 'month_end_schedule_id' => $f['august']->id, 'branch_id' => $store->id,
        'sap_masterfile_id' => $sapId, 'item_code' => 'RM-ESP', 'item_name' => 'Espresso', 'uom' => 'Bag',
        'total_qty' => $qty, 'status' => $status, 'created_by' => $f['user']->id,
    ]);
}

function mecSohEspresso(): array
{
    $ids = [];
    foreach ([['Gm', 'Bag', 1000, 1], ['Bag', 'Bag', 1, 1]] as [$alt, $base, $altQty, $baseQty]) {
        $ids[$alt] = SAPMasterfile::create([
            'ItemCode' => 'RM-ESP', 'ItemDescription' => 'Espresso Blend',
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ])->id;
    }

    return $ids;
}

it('blocks a store on every unfinished transaction this month, and only this month', function () {
    $f = mecSohFixture();
    ['A' => $a, 'B' => $b, 'C' => $c] = $f['stores']->all();
    $sap = mecSohEspresso();

    foreach (['pending', 'approved', 'committed', 'incomplete'] as $status) {
        mecSohOrder($f, $a, '2026-09-10', $status);
    }
    // Received, but one receipt line still waits for approval.
    $received = mecSohOrder($f, $a, '2026-09-11', 'received');
    $line = DB::table('store_order_items')->insertGetId([
        'store_order_id' => $received, 'item_code' => 'RM-ESP', 'uom' => 'Bag',
        'quantity_ordered' => 1, 'quantity_approved' => 1, 'quantity_commited' => 1, 'cost_per_quantity' => 0, 'total_cost' => 0,
    ]);
    DB::table('ordered_item_receive_dates')->insert(['store_order_item_id' => $line, 'quantity_received' => 1, 'status' => 'received']);

    // Interco: A receives one still in transit, and has not committed one it sends.
    mecSohOrder($f, $a, '2026-09-12', 'approved', ['interco_number' => 'IC-1', 'interco_status' => 'in_transit', 'sending_store_branch_id' => $c->id]);
    mecSohOrder($f, $c, '2026-09-12', 'approved', ['interco_number' => 'IC-2', 'interco_status' => 'approved', 'sending_store_branch_id' => $a->id]);

    mecSohWastage($a, $f['user'], 'W-1', 'pending', '2026-09-13 09:00:00');
    mecSohWastage($a, $f['user'], 'W-2', 'approved_lvl1', '2026-09-13 09:00:00');
    mecSohWastage($a, $f['user'], 'W-3', 'approved_lvl2', '2026-09-13 09:00:00');

    // August's count is the beginning balance: A's is not approved yet, B's is.
    mecSohMecCount($f, $a, $sap['Bag'], 'level1_approved');
    mecSohMecCount($f, $b, $sap['Bag'], 'level2_approved');

    DB::table('product_inventory_stock_managers')->insert([
        'product_inventory_id' => $sap['Bag'], 'store_branch_id' => $a->id, 'quantity' => 1, 'action' => 'soh_adjustment',
        'unit_cost' => 0, 'total_cost' => 0, 'transaction_date' => '2026-09-15', 'is_stock_adjustment_approved' => false,
    ]);

    // Outside the period: last month and next month never block.
    mecSohOrder($f, $b, '2026-08-20', 'pending');
    mecSohOrder($f, $b, '2026-10-05', 'committed');
    mecSohWastage($b, $f['user'], 'W-9', 'pending', '2026-08-15 09:00:00');

    $readiness = app(MonthEndCountReadinessService::class);
    // A and B submitted August's count, so both are on September, to date; C still owes
    // August, whose period ended on its MEC Scheduled Date.
    expect($readiness->periods([$a->id, $b->id, $c->id], Carbon::today('Asia/Manila')))->toBe([
        $a->id => ['2026-09-01', '2026-09-28'],
        $b->id => ['2026-09-01', '2026-09-28'],
        $c->id => ['2026-08-01', '2026-08-31'],
    ]);

    $blockers = $readiness->blockers([$a->id, $b->id], '2026-09-01', '2026-09-28');

    expect(collect($blockers[$a->id])->pluck('count', 'key')->all())->toEqual([
        'orders_awaiting_approval' => 1,
        // Approved and committed alike: committing is not a prerequisite of receiving.
        'orders_not_yet_received' => 2,
        'orders_not_yet_fully_received' => 1,
        'receipt_approval' => 1,
        'interco_receive' => 1,
        'wastage_level1' => 1,
        'wastage_level2' => 1,
        'previous_count' => 1,
        // The interco A has not committed as sender, and its unapproved SOH adjustment, do not block.
    ])->and($blockers)->not->toHaveKey($b->id);
});

it('fills Current SOH with the Theoretical SOH of the report, in each line\'s Bulk UOM', function () {
    $f = mecSohFixture();
    $a = $f['stores']['A'];
    $sap = mecSohEspresso();

    // Beginning 2 Bag (August count) + received 3 Bag - wastage 500 Gm = 4.5 Bag.
    mecSohMecCount($f, $a, $sap['Bag'], 'level2_approved', 2);
    $order = mecSohOrder($f, $a, '2026-09-10', 'received');
    $line = DB::table('store_order_items')->insertGetId([
        'store_order_id' => $order, 'item_code' => 'RM-ESP', 'uom' => 'Bag',
        'quantity_ordered' => 3, 'quantity_approved' => 3, 'quantity_commited' => 3, 'cost_per_quantity' => 0, 'total_cost' => 0,
    ]);
    DB::table('ordered_item_receive_dates')->insert(['store_order_item_id' => $line, 'quantity_received' => 3, 'status' => 'approved']);
    mecSohWastage($a, $f['user'], 'W-1', 'approved_lvl2', '2026-09-12 09:00:00', $sap['Gm'], 500);

    foreach ([['RM-ESP', 'Bag'], ['RM-ESP', 'Gm'], ['RM-NONE', 'Pc']] as [$code, $uom]) {
        MonthEndCountTemplate::create(['item_code' => $code, 'item_name' => $code, 'uom' => $uom, 'is_active' => true, 'created_by' => $f['user']->id]);
    }

    $reportTheoretical = collect(app(InventoryMovementService::class)->movementData(
        SAPMasterfile::where('ItemCode', 'RM-ESP')->get(),
        ['branch_id' => $a->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-28']
    ))->firstWhere('sap_code', 'RM-ESP')['theoretical_qty'];
    expect($reportTheoretical)->toEqual(4.5);

    Excel::fake();
    test()->actingAs($f['user']);
    app(MonthEndCountController::class)->downloadTemplate(Request::create('/month-end-count/download', 'GET', ['branch_id' => $a->id]));

    Excel::assertDownloaded('month_end_count_template_'.Carbon::now()->format('Ymd_His').'.xlsx', function (MonthEndCountDownloadExport $export) {
        $soh = $export->collection()->mapWithKeys(fn ($row) => [$row['Item Code'].'|'.$row['Bulk UOM'] => $row['Current SOH']]);

        // A line with no SAP item shows zero, and the writer must not drop zeros as blanks.
        return $soh['RM-ESP|Bag'] == 4.5 && $soh['RM-ESP|Gm'] == 4500 && $soh['RM-NONE|Pc'] === 0
            && $export instanceof WithStrictNullComparison
            && $export->collection()->first()['Bulk Qty'] === null;
    });
});

it('keeps Current SOH on the month being counted once the calendar rolls into the next month', function () {
    $f = mecSohFixture();
    $a = $f['stores']['A'];
    $sap = mecSohEspresso();

    // September's count falls due on the 30th; the store takes it on October 1.
    MonthEndSchedule::create(['year' => 2026, 'month' => 9, 'calculated_date' => '2026-09-30', 'created_by' => $f['user']->id]);
    MonthEndSchedule::create(['year' => 2026, 'month' => 10, 'calculated_date' => '2026-10-30', 'created_by' => $f['user']->id]);
    mecSohMecCount($f, $a, $sap['Bag'], 'level2_approved', 2);
    $order = mecSohOrder($f, $a, '2026-09-10', 'received');
    $line = DB::table('store_order_items')->insertGetId([
        'store_order_id' => $order, 'item_code' => 'RM-ESP', 'uom' => 'Bag',
        'quantity_ordered' => 3, 'quantity_approved' => 3, 'quantity_commited' => 3, 'cost_per_quantity' => 0, 'total_cost' => 0,
    ]);
    DB::table('ordered_item_receive_dates')->insert(['store_order_item_id' => $line, 'quantity_received' => 3, 'status' => 'approved']);
    MonthEndCountTemplate::create(['item_code' => 'RM-ESP', 'item_name' => 'RM-ESP', 'uom' => 'Bag', 'is_active' => true, 'created_by' => $f['user']->id]);

    Carbon::setTestNow(Carbon::parse('2026-10-01 10:00', 'Asia/Manila'));
    $readiness = app(MonthEndCountReadinessService::class);
    expect($readiness->periods([$a->id], Carbon::today('Asia/Manila')))->toBe([$a->id => ['2026-09-01', '2026-09-30']]);

    Excel::fake();
    test()->actingAs($f['user']);
    app(MonthEndCountController::class)->downloadTemplate(Request::create('/month-end-count/download', 'GET', ['branch_id' => $a->id]));

    // August's 2 Bag + 3 received in September, not an empty October.
    Excel::assertDownloaded('month_end_count_template_'.Carbon::now()->format('Ymd_His').'.xlsx',
        fn (MonthEndCountDownloadExport $export) => $export->collection()->first()['Current SOH'] == 5);

    // Once September's count is in, the template moves on to October.
    $september = MonthEndSchedule::where('year', 2026)->where('month', 9)->first();
    DB::table('month_end_count_items')->insert([
        'entity_id' => $september->entity_id, 'month_end_schedule_id' => $september->id, 'branch_id' => $a->id,
        'sap_masterfile_id' => $sap['Bag'], 'item_code' => 'RM-ESP', 'item_name' => 'Espresso', 'uom' => 'Bag',
        'total_qty' => 4, 'status' => 'uploaded', 'created_by' => $f['user']->id,
    ]);
    expect($readiness->periods([$a->id], Carbon::today('Asia/Manila')))->toBe([$a->id => ['2026-10-01', '2026-10-01']]);
});

it('does not hold a count back on transactions dated after its MEC Scheduled Date', function () {
    $f = mecSohFixture();
    $a = $f['stores']['A'];
    $sap = mecSohEspresso();

    // September's count, taken on October 5. August's is approved, so nothing older blocks.
    $september = MonthEndSchedule::create(['year' => 2026, 'month' => 9, 'calculated_date' => '2026-09-30', 'created_by' => $f['user']->id]);
    mecSohMecCount($f, $a, $sap['Bag'], 'level2_approved');
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Manila'));
    $today = Carbon::today('Asia/Manila');

    // October's orders and wastage belong to October's count.
    mecSohOrder($f, $a, '2026-10-02', 'pending');
    mecSohOrder($f, $a, '2026-10-05', 'committed');
    mecSohOrder($f, $a, '2026-10-05', 'committed');
    mecSohWastage($a, $f['user'], 'W-OCT', 'pending', '2026-10-03 09:00:00');

    $readiness = app(MonthEndCountReadinessService::class);
    $periods = $readiness->periods([$a->id], $today);

    expect($periods)->toBe([$a->id => ['2026-09-01', '2026-09-30']])
        ->and($readiness->uploadPeriod($september, $today))->toBe(['2026-09-01', '2026-09-30'])
        ->and($readiness->blockersForPeriods($periods))->toBe([])
        ->and($readiness->blockersForUpload($september, [$a->id], $today))->toBe([]);

    // An order of the last day counted still holds it back.
    mecSohOrder($f, $a, '2026-09-30', 'committed');
    expect(array_column($readiness->blockersForPeriods($periods)[$a->id], 'label'))->toBe(['1 order not yet received'])
        ->and(array_column($readiness->blockersForUpload($september, [$a->id], $today)[$a->id], 'label'))->toBe(['1 order not yet received']);

    // A count dated in the following month is settled to the end of the month counted,
    // and one whose date is still ahead is settled to today.
    $march = new MonthEndSchedule(['year' => 2026, 'month' => 3, 'calculated_date' => '2026-04-05']);
    $october = new MonthEndSchedule(['year' => 2026, 'month' => 10, 'calculated_date' => '2026-10-30']);
    expect($readiness->uploadPeriod($march, $today))->toBe(['2026-03-01', '2026-03-31'])
        ->and($readiness->uploadPeriod($october, $today))->toBe(['2026-10-01', '2026-10-05']);
});

it('refuses the template while the store has unfinished transactions, or is not the user\'s', function () {
    $f = mecSohFixture();
    ['A' => $a] = $f['stores']->all();
    // A still owes August's count, so its period is August.
    mecSohWastage($a, $f['user'], 'W-1', 'pending', '2026-08-13 09:00:00');

    Excel::fake();
    test()->actingAs($f['user']);
    $response = app(MonthEndCountController::class)->downloadTemplate(Request::create('/month-end-count/download', 'GET', ['branch_id' => $a->id]));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and(session('errors')->get('download')[0])->toContain('1 wastage report awaiting level 1 approval');

    $outsider = StoreBranch::create(['branch_code' => 'TX', 'brand_code' => 'TX', 'name' => 'Not Mine', 'store_status' => 'Active', 'is_active' => 1]);
    expect(fn () => app(MonthEndCountController::class)->downloadTemplate(Request::create('/month-end-count/download', 'GET', ['branch_id' => $outsider->id])))
        ->toThrow(HttpException::class);
});

it('withholds the upload from a store with unfinished transactions, on the page and on the server', function () {
    $f = mecSohFixture();
    ['A' => $a, 'B' => $b, 'C' => $c] = $f['stores']->all();

    // The day after August's count: the upload window is open for every store.
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Asia/Manila'));
    mecSohOrder($f, $a, '2026-08-20', 'committed');

    test()->actingAs($f['user']);
    $request = fn () => Request::create('/month-end-count', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
    $page = app(MonthEndCountController::class)->index($request())->toResponse($request())->getData(true)['props'];

    // A is not offered the upload; it is listed with what it still has to finish.
    expect(array_keys($page['branchesAwaitingUpload']))->toEqualCanonicalizing([$b->id, $c->id])
        ->and($page['uploadPendingBranches'])->toHaveCount(1)
        ->and($page['uploadPendingBranches'][0]['id'])->toBe($a->id)
        ->and($page['uploadPendingBranches'][0]['blockers'][0]['label'])->toBe('1 order not yet received')
        ->and($page['uploadPendingPeriod'])->toBe(['from' => 'Aug 1, 2026', 'through' => 'Aug 31, 2026']);

    Excel::fake();
    $upload = fn (StoreBranch $store) => app(MonthEndCountController::class)->upload(Request::create(
        '/month-end-count/upload', 'POST', ['schedule_id' => $f['august']->id, 'branch_id' => $store->id], [],
        ['file' => UploadedFile::fake()->create('count.xlsx', 5, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')]
    ));

    // A stale page or a direct request is refused too.
    $refused = $upload($a);
    expect($refused)->toBeInstanceOf(RedirectResponse::class)
        ->and($refused->getTargetUrl())->not->toContain('review')
        ->and(session('errors')->get('error')[0])->toContain('Store A still has unfinished transactions from Aug 1, 2026 to Aug 31, 2026: 1 order not yet received');

    expect($upload($b)->getTargetUrl())->toBe(route('month-end-count.review', ['schedule' => $f['august']->id, 'branch' => $b->id]));

    // Once the order is received, A gets the upload back.
    DB::table('store_orders')->where('store_branch_id', $a->id)->update(['order_status' => 'received']);
    $page = app(MonthEndCountController::class)->index($request())->toResponse($request())->getData(true)['props'];
    expect(array_keys($page['branchesAwaitingUpload']))->toContain($a->id)
        ->and($page['uploadPendingBranches'])->toBe([]);
});
