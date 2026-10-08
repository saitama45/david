<?php

use App\Exports\MonthEndCountDownloadExport;
use App\Http\Controllers\MonthEndCountController;
use App\Models\Entity;
use App\Models\MonthEndCountItem;
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

// The controller is called directly: every test HTTP request disconnects the database on
// terminate(), which rolls back RefreshDatabase's transaction. The file is a real workbook
// written by the template's own export, so the import runs as it does for a store.

afterEach(fn () => Carbon::setTestNow());

/** August's count (Aug 31) on Sep 1, inside the upload window, for a store with nothing unfinished. */
function mecUploadFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Asia/Manila'));

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $store = StoreBranch::create(['branch_code' => 'TST', 'brand_code' => 'TST', 'name' => 'Test Store', 'store_status' => 'Active', 'is_active' => 1]);
    UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);
    $schedule = MonthEndSchedule::create(['year' => 2026, 'month' => 8, 'calculated_date' => '2026-08-31', 'created_by' => $user->id]);

    return compact('user', 'store', 'schedule');
}

/** Uploads the store's filled-in count sheet: lines of [item code, conversion, bulk qty, loose qty]. */
function uploadMecCount(array $f, array $lines)
{
    $rows = collect($lines)->map(function (array $line) {
        [$code, $conversion, $bulk, $loose] = $line;
        SAPMasterfile::create(['ItemCode' => $code, 'ItemDescription' => $code, 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'Case', 'BaseUOM' => 'Case', 'is_active' => true]);

        return [
            'Item Code' => $code, 'Item Name' => $code, 'Category 1' => '', 'Area' => '', 'Category 2' => '',
            'Packaging' => '', 'Conversion' => $conversion, 'Bulk UOM' => 'Case', 'Loose UOM' => 'Gm',
            'Current SOH' => 0, 'Bulk Qty' => $bulk, 'Loose Qty' => $loose, 'Remarks' => '',
        ];
    });

    $path = tempnam(sys_get_temp_dir(), 'mec');
    file_put_contents($path, Excel::raw(new MonthEndCountDownloadExport($rows), \Maatwebsite\Excel\Excel::XLSX));

    test()->actingAs($f['user']);

    try {
        return app(MonthEndCountController::class)->upload(Request::create(
            '/month-end-count/upload', 'POST', ['schedule_id' => $f['schedule']->id, 'branch_id' => $f['store']->id], [],
            ['file' => new UploadedFile($path, 'count.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)]
        ));
    } finally {
        @unlink($path);
    }
}

it('saves a count whose loose quantity is a tiny fraction of the bulk unit', function () {
    $f = mecUploadFixture();

    // 1 Gm loose of an 18,720 Gm Case is 0.0000534 Case, which PHP hands the database as
    // "5.3418803418803E-5". SQL Server refuses to read that as a decimal and rolls the whole
    // upload back, so the store only ever saw "Cannot roll back trans3".
    $response = uploadMecCount($f, [
        ['RM-SYRUP', 18720, '', 1],
        ['RM-SUGAR', 1000, 2, 500],
        ['RM-CUPS', 3, 1, 1],
    ]);

    expect(session('errors')?->get('error'))->toBeNull()
        ->and($response->getTargetUrl())->toBe(route('month-end-count.review', ['schedule' => $f['schedule']->id, 'branch' => $f['store']->id]));

    $counted = fn (string $column) => MonthEndCountItem::where('month_end_schedule_id', $f['schedule']->id)
        ->where('branch_id', $f['store']->id)->pluck($column, 'item_code')->all();
    $totals = ['RM-SYRUP' => '0.0001', 'RM-SUGAR' => '2.5000', 'RM-CUPS' => '1.3333'];

    expect($counted('total_qty'))->toEqual($totals);

    // Submitting works the total out again from the saved quantities, and hit the same wall.
    $submitted = app(MonthEndCountController::class)->submitForApproval(Request::create('/month-end-count/submit', 'POST'), $f['schedule'], $f['store']);

    expect(session('errors')?->get('error'))->toBeNull()
        ->and($submitted->getTargetUrl())->toBe(route('month-end-count.index'))
        ->and($counted('total_qty'))->toEqual($totals)
        ->and(array_values(array_unique($counted('status'))))->toBe(['pending_level1_approval']);
});
