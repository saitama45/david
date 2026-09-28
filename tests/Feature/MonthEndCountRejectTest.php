<?php

use App\Http\Controllers\MonthEndCountApprovalController;
use App\Http\Controllers\MonthEndCountController;
use App\Http\Controllers\MonthEndScheduleController;
use App\Models\Entity;
use App\Models\MonthEndCountItem;
use App\Models\MonthEndCountRejection;
use App\Models\MonthEndCountReopen;
use App\Models\MonthEndSchedule;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Controllers are called directly: every test HTTP request disconnects the
// database on terminate(), which rolls back RefreshDatabase's transaction.

afterEach(fn () => Carbon::setTestNow());

/**
 * August's count (Aug 31), awaiting Level 1, on Sep 28 - long after the default
 * upload window closed (Sep 2, 11:59 PM).
 */
function mecRejectFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-28 10:00', 'Asia/Manila'));

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    Permission::findOrCreate('approve month end count level 1');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $store = StoreBranch::create(['branch_code' => 'TST', 'brand_code' => 'TST', 'name' => 'Test Store', 'store_status' => 'Active', 'is_active' => 1]);

    $storeUser = User::factory()->create();
    UserAssignedStoreBranch::create(['user_id' => $storeUser->id, 'store_branch_id' => $store->id]);
    $approver = User::factory()->create();
    $approver->givePermissionTo('approve month end count level 1');

    $schedule = MonthEndSchedule::create(['year' => 2026, 'month' => 8, 'calculated_date' => '2026-08-31', 'created_by' => $approver->id]);

    foreach (['ITEM-A', 'ITEM-B'] as $code) {
        $sap = SAPMasterfile::create(['ItemCode' => $code, 'ItemDescription' => $code, 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'PC', 'BaseUOM' => 'PC', 'is_active' => true]);
        MonthEndCountItem::create([
            'month_end_schedule_id' => $schedule->id,
            'branch_id' => $store->id,
            'sap_masterfile_id' => $sap->id,
            'item_code' => $code,
            'item_name' => $code,
            'uom' => 'PC',
            'total_qty' => 5,
            'status' => 'pending_level1_approval',
            'created_by' => $storeUser->id,
        ]);
    }

    return compact('store', 'storeUser', 'approver', 'schedule');
}

function rejectMecAs(User $user, MonthEndSchedule $schedule, StoreBranch $store, array $input)
{
    test()->actingAs($user);

    return app(MonthEndCountApprovalController::class)->rejectLevel1(
        Request::create('/month-end-count-approvals/reject', 'POST', $input),
        $schedule->id,
        $store->id
    );
}

function mecItemStatuses(MonthEndSchedule $schedule, StoreBranch $store): array
{
    return MonthEndCountItem::where('month_end_schedule_id', $schedule->id)
        ->where('branch_id', $store->id)->pluck('status')->all();
}

it('rejects a count and reopens its upload for that store even after the window closed', function () {
    ['store' => $store, 'storeUser' => $storeUser, 'approver' => $approver, 'schedule' => $schedule] = mecRejectFixture();

    $response = rejectMecAs($approver, $schedule, $store, [
        'reason' => 'Sugar counted in grams, not bags.',
        'reupload_until' => '2026-10-01 23:59:00',
    ]);

    expect($response->getTargetUrl())->toBe(route('month-end-count-approvals.index'))
        ->and(mecItemStatuses($schedule, $store))->toBe(['rejected', 'rejected']);

    $rejection = MonthEndCountRejection::sole();
    expect($rejection->reason)->toBe('Sugar counted in grams, not bags.')
        ->and($rejection->item_count)->toBe(2)
        ->and((int) $rejection->rejected_by)->toBe($approver->id);

    $reopen = MonthEndCountReopen::sole();
    expect((int) $reopen->branch_id)->toBe($store->id)
        ->and($reopen->reopened_until->format('Y-m-d H:i:s'))->toBe('2026-10-01 23:59:00');

    // The store sees the upload form again, with the reason.
    test()->actingAs($storeUser);
    $page = app(MonthEndCountController::class)
        ->index(Request::create('/month-end-count', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']))
        ->toResponse(Request::create('/month-end-count', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']))
        ->getData(true)['props'];

    expect($page['uploadSchedule']['id'])->toBe($schedule->id)
        ->and(array_keys($page['branchesAwaitingUpload']))->toBe([$store->id])
        ->and($page['uploadWindow']['state'])->toBe('open')
        ->and($page['returnedCounts'][0]['reason'])->toBe('Sugar counted in grams, not bags.');

    // Store Progress treats the rejected store as owing a count, so support can extend it.
    $details = app(MonthEndScheduleController::class)->getDetails(Request::create('/details', 'GET'), $schedule)->getData(true);
    expect($details['data'][0]['status'])->toBe('Rejected')
        ->and($details['data'][0]['can_reopen'])->toBeTrue();
});

it('lets the store re-upload after a rejection, replacing the rejected rows', function () {
    ['store' => $store, 'storeUser' => $storeUser, 'approver' => $approver, 'schedule' => $schedule] = mecRejectFixture();
    rejectMecAs($approver, $schedule, $store, ['reason' => 'Recount the freezer.', 'reupload_until' => '2026-10-01 23:59:00']);

    Excel::fake();
    test()->actingAs($storeUser);
    $file = UploadedFile::fake()->create('count.xlsx', 5, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response = app(MonthEndCountController::class)->upload(
        Request::create('/month-end-count/upload', 'POST', ['schedule_id' => $schedule->id, 'branch_id' => $store->id], [], ['file' => $file])
    );

    expect($response->getTargetUrl())->toBe(route('month-end-count.review', ['schedule' => $schedule->id, 'branch' => $store->id]))
        ->and(mecItemStatuses($schedule, $store))->toBe([])
        ->and(MonthEndCountRejection::count())->toBe(1);
});

it('refuses a rejection that is not allowed and changes nothing', function () {
    ['store' => $store, 'storeUser' => $storeUser, 'approver' => $approver, 'schedule' => $schedule] = mecRejectFixture();

    // A deadline already past.
    rejectMecAs($approver, $schedule, $store, ['reason' => 'x', 'reupload_until' => '2026-09-27 10:00:00']);
    expect(mecItemStatuses($schedule, $store))->toBe(['pending_level1_approval', 'pending_level1_approval']);

    // No reason.
    expect(fn () => rejectMecAs($approver, $schedule, $store, ['reason' => '', 'reupload_until' => '2026-10-01 23:59:00']))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    // Without the Level 1 approver permission.
    expect(fn () => rejectMecAs($storeUser, $schedule, $store, ['reason' => 'x', 'reupload_until' => '2026-10-01 23:59:00']))
        ->toThrow(HttpException::class);

    // A count already past Level 1 can no longer be rejected here.
    MonthEndCountItem::where('month_end_schedule_id', $schedule->id)->update(['status' => 'level1_approved']);
    rejectMecAs($approver, $schedule, $store, ['reason' => 'x', 'reupload_until' => '2026-10-01 23:59:00']);

    expect(mecItemStatuses($schedule, $store))->toBe(['level1_approved', 'level1_approved'])
        ->and(MonthEndCountRejection::count())->toBe(0)
        ->and(MonthEndCountReopen::count())->toBe(0);
});

it('does not shorten a later reopen support already granted', function () {
    ['store' => $store, 'approver' => $approver, 'schedule' => $schedule] = mecRejectFixture();
    MonthEndCountReopen::create([
        'entity_id' => $schedule->entity_id, 'month_end_schedule_id' => $schedule->id, 'branch_id' => $store->id,
        'reopened_until' => '2026-10-10 23:59:00', 'reopened_by' => $approver->id,
    ]);

    rejectMecAs($approver, $schedule, $store, ['reason' => 'Recount.', 'reupload_until' => '2026-10-01 23:59:00']);

    expect(MonthEndCountReopen::sole()->reopened_until->format('Y-m-d H:i:s'))->toBe('2026-10-10 23:59:00');
});
