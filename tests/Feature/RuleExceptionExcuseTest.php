<?php

use App\Enums\RuleExceptionStatus;
use App\Http\Services\AdoptionRateTrackingService;
use App\Http\Services\RuleExceptionService;
use App\Http\Services\SuccessRateService;
use App\Models\Entity;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Excuse rules never block anything: an approved excuse only turns a late
 * Adoption Rate row into "Excused". Driven through sales.late_upload on the
 * isolated daviddb_test database; nothing is deleted or soft-deleted.
 */
afterEach(fn () => Carbon::setTestNow());

it('excuses a missed sales upload: Excused in the report, out of the rate, still a transaction', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-16 10:00', 'Asia/Manila'));

    $entity = Entity::create(['name' => 'Excuse Entity', 'code' => 'EX'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    foreach (['create store transactions', 'approve store transactions'] as $name) {
        Permission::findOrCreate($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $store = StoreBranch::create(['branch_code' => 'EXC', 'brand_code' => 'EXC', 'name' => 'Excuse Store', 'store_status' => 'Active', 'is_active' => 1]);
    $storeRep = User::factory()->create();
    $storeRep->givePermissionTo('create store transactions');
    $approver = User::factory()->create();
    $approver->givePermissionTo('approve store transactions');
    foreach ([$storeRep, $approver] as $user) {
        UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);
    }

    $adoption = app(AdoptionRateTrackingService::class);
    $filters = ['date_from' => '2026-09-14', 'date_to' => '2026-09-14', 'store_ids' => [$store->id]];
    $rowKey = "SALES_UPLOAD|{$store->id}|2026-09-14";

    // Monday's sales were never uploaded: the report scores the day late.
    $before = $adoption->getSalesUploadTimelinessData($filters, $storeRep, false);
    expect($before['rows']->firstWhere('row_key', $rowKey)['sales_report_uploaded_on_time'])->toBe('No')
        ->and($before['totals']['adoption_rate'])->toBe(0.0);

    $service = app(RuleExceptionService::class);
    $request = $service->submit(
        $storeRep,
        ['rule_key' => 'sales.late_upload', 'reason_code' => 'data_unavailable', 'justification' => 'POS export failed on Monday night; IT restored it Wednesday.'],
        ['row_key' => $rowKey],
    );

    // An excuse is never time-boxed and is final on approval.
    $approved = $service->approve($approver, $request, null, null);
    expect($approved->status)->toBe(RuleExceptionStatus::APPROVED)
        ->and($approved->valid_until)->toBeNull();

    $after = app(AdoptionRateTrackingService::class)->getSalesUploadTimelinessData($filters, $storeRep, false);
    $row = $after['rows']->firstWhere('row_key', $rowKey);

    expect($row['sales_report_uploaded_on_time'])->toBe(AdoptionRateTrackingService::EXCUSED)
        ->and($row['excuse_reason'])->toContain('POS export failed')
        ->and($after['totals']['excused'])->toBe(1)
        ->and($after['totals']['adoption_rate'])->toBeNull(); // no Yes/No days left to rate

    // It still happened, so Success Rate keeps counting it as a transaction.
    $countsAsTransaction = new ReflectionMethod(SuccessRateService::class, 'countsAsTransaction');
    $countsAsTransaction->setAccessible(true);
    expect($countsAsTransaction->invoke(app(SuccessRateService::class), $row, ['sales_report_uploaded_on_time']))->toBeTrue();

    // Nothing left to excuse now.
    expect(fn () => $service->submit(
        $storeRep,
        ['rule_key' => 'sales.late_upload', 'reason_code' => 'data_unavailable', 'justification' => 'Duplicate request for the same missed day.'],
        ['row_key' => $rowKey],
    ))->toThrow(ValidationException::class);
});
