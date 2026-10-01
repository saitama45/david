<?php

use App\Enums\RuleExceptionStatus;
use App\Http\Services\AdoptionRateTrackingService;
use App\Http\Services\GoLiveStoresService;
use App\Http\Services\RuleExceptionService;
use App\Http\Services\SuccessRateService;
use App\Models\Entity;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Models\Wastage;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Excuse rules never block anything: an approved excuse only turns a late
 * Adoption Rate row into "Excused". Driven through wastage.late_upload on the
 * isolated test database; nothing is deleted or soft-deleted.
 *
 * The Adoption Rate report only holds go-live stores, so the fixture store is
 * made live unless a test says otherwise.
 */
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: StoreBranch, 1: User, 2: User} store, requester, approver */
function excuseFixture(string $requestPermission, string $approvePermission, bool $live = true): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-16 10:00', 'Asia/Manila'));

    $entity = Entity::create(['name' => 'Excuse Entity', 'code' => 'EX'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    foreach ([$requestPermission, $approvePermission] as $name) {
        Permission::findOrCreate($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $store = StoreBranch::create(['branch_code' => 'EXC', 'brand_code' => 'EXC', 'name' => 'Excuse Store', 'store_status' => 'Active', 'is_active' => 1]);
    $storeRep = User::factory()->create();
    $storeRep->givePermissionTo($requestPermission);
    $approver = User::factory()->create();
    $approver->givePermissionTo($approvePermission);
    foreach ([$storeRep, $approver] as $user) {
        UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);
    }

    if ($live) {
        // Stands in for the store's first /mass-orders order, placed weeks earlier.
        app()->instance(GoLiveStoresService::class, new class extends GoLiveStoresService
        {
            public function goLiveDates(array $storeIds): array
            {
                return array_fill_keys($storeIds, '2026-08-03');
            }
        });
    }

    return [$store, $storeRep, $approver];
}

it('excuses a late wastage record: Excused in the report, out of the rate, still a transaction', function () {
    [$store, $storeRep, $approver] = excuseFixture('create wastage record', 'approve wastage level 1');

    // Monday's wastage was only recorded on Wednesday: the report scores it late.
    $wastage = Wastage::create([
        'store_branch_id' => $store->id,
        'wastage_no' => 'WST-EXCUSE-1',
        'wastage_date' => '2026-09-14',
        'wastage_qty' => 1,
        'cost' => 10,
        'reason' => 'Spoilage',
        'created_by' => $storeRep->id,
    ]);

    $adoption = app(AdoptionRateTrackingService::class);
    $filters = ['date_from' => '2026-09-14', 'date_to' => '2026-09-14', 'store_ids' => [$store->id]];
    $rowKey = "WASTAGE_UPLOAD:{$wastage->id}|{$store->id}|2026-09-14";

    $before = $adoption->getWastageUploadTimelinessData($filters, $storeRep, false);
    expect($before['rows']->firstWhere('row_key', $rowKey)['wastage_report_uploaded'])->toBe('No')
        ->and($before['totals']['upload_adoption_rate'])->toBe(0.0);

    $service = app(RuleExceptionService::class);
    $request = $service->submit(
        $storeRep,
        ['rule_key' => 'wastage.late_upload', 'reason_code' => 'store_operations', 'justification' => 'Store was closed Tuesday for the typhoon; recorded on reopening.'],
        ['row_key' => $rowKey],
    );

    // An excuse is never time-boxed and is final on approval.
    $approved = $service->approve($approver, $request, null, null);
    expect($approved->status)->toBe(RuleExceptionStatus::APPROVED)
        ->and($approved->valid_until)->toBeNull();

    $after = app(AdoptionRateTrackingService::class)->getWastageUploadTimelinessData($filters, $storeRep, false);
    $row = $after['rows']->firstWhere('row_key', $rowKey);

    expect($row['wastage_report_uploaded'])->toBe(AdoptionRateTrackingService::EXCUSED)
        ->and($row['excuse_reason'])->toContain('closed Tuesday')
        ->and($after['totals']['upload_excused'])->toBe(1)
        ->and($after['totals']['upload_adoption_rate'])->toBeNull(); // no Yes/No rows left to rate

    // It still happened, so Success Rate keeps counting it as a transaction.
    $countsAsTransaction = new ReflectionMethod(SuccessRateService::class, 'countsAsTransaction');
    $countsAsTransaction->setAccessible(true);
    expect($countsAsTransaction->invoke(app(SuccessRateService::class), $row, ['wastage_report_uploaded']))->toBeTrue();

    // Nothing left to excuse now.
    expect(fn () => $service->submit(
        $storeRep,
        ['rule_key' => 'wastage.late_upload', 'reason_code' => 'store_operations', 'justification' => 'Duplicate request for the same late record.'],
        ['row_key' => $rowKey],
    ))->toThrow(ValidationException::class);
});

it('scores a go-live store sales day with no upload as on time, leaving nothing to excuse', function () {
    [$store, $storeRep] = excuseFixture('create store transactions', 'approve store transactions');

    $filters = ['date_from' => '2026-09-14', 'date_to' => '2026-09-14', 'store_ids' => [$store->id]];
    $rowKey = "SALES_UPLOAD|{$store->id}|2026-09-14";

    // Sales post automatically from the POS, so a live store's day is never scored late.
    $data = app(AdoptionRateTrackingService::class)->getSalesUploadTimelinessData($filters, $storeRep, false);
    $row = $data['rows']->firstWhere('row_key', $rowKey);

    expect($row['sales_report_uploaded_on_time'])->toBe('Yes')
        ->and($row['sales_report_uploaded'])->toBe('No')
        ->and($data['totals']['adoption_rate'])->toBe(100.0);

    expect(fn () => app(RuleExceptionService::class)->submit(
        $storeRep,
        ['rule_key' => 'sales.late_upload', 'reason_code' => 'data_unavailable', 'justification' => 'POS export failed on Monday night; IT restored it Wednesday.'],
        ['row_key' => $rowKey],
    ))->toThrow(ValidationException::class);
});

it('leaves a store that is not live out of the adoption rate, but not out of My Actions', function () {
    [$store, $storeRep] = excuseFixture('create store transactions', 'approve store transactions', live: false);

    $filters = ['date_from' => '2026-09-14', 'date_to' => '2026-09-14', 'store_ids' => [$store->id]];
    $adoption = app(AdoptionRateTrackingService::class);

    // Never ordered through /mass-orders: no rows, so nothing to rate or to count as a transaction.
    $data = $adoption->getSalesUploadTimelinessData($filters, $storeRep, false);
    expect($data['rows'])->toBeEmpty()
        ->and($data['totals']['days'])->toBe(0)
        ->and($data['totals']['adoption_rate'])->toBeNull();

    $overall = $adoption->getOverallAdoptionRateData($filters, $storeRep, false);
    expect($overall['rows'])->toBeEmpty()
        ->and($overall['totals']['overall_rate'])->toBeNull();

    // My Actions still sees the missed upload it has to chase.
    $all = $adoption->getSalesUploadTimelinessData($filters + ['include_not_live' => true], $storeRep, false);
    expect($all['rows']->firstWhere('row_key', "SALES_UPLOAD|{$store->id}|2026-09-14")['sales_report_uploaded_on_time'])->toBe('No');
});
