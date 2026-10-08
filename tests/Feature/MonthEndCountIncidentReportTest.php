<?php

use App\Http\Controllers\MonthEndCountController;
use App\Http\Controllers\MonthEndCountIncidentReportController;
use App\Http\Controllers\MonthEndScheduleController;
use App\Http\Services\MonthEndCountIncidentReportService;
use App\Models\Entity;
use App\Models\MonthEndCountIncidentReport;
use App\Models\MonthEndCountSetting;
use App\Models\MonthEndSchedule;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Support\EntityContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Controllers are called directly: every test HTTP request disconnects the database on
// terminate(), which rolls back RefreshDatabase's transaction.

afterEach(fn () => Carbon::setTestNow());

/**
 * August's count (Aug 31) on Sep 1, inside the upload window. Store A has an August order
 * that is not received yet; Store B has nothing pending.
 */
function mecIrFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Asia/Manila'));

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Store']);
    $stores = collect(['A' => 'Store A', 'B' => 'Store B'])->map(fn ($name, $code) => StoreBranch::create([
        'branch_code' => 'T'.$code, 'brand_code' => 'T'.$code, 'name' => $name, 'store_status' => 'Active', 'is_active' => 1,
    ]));
    foreach ($stores as $store) {
        UserAssignedStoreBranch::create(['user_id' => $user->id, 'store_branch_id' => $store->id]);
    }

    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'TGIT', 'name' => 'TGI Test', 'is_active' => true]);
    $schedule = MonthEndSchedule::create(['year' => 2026, 'month' => 8, 'calculated_date' => '2026-08-31', 'created_by' => $user->id]);
    $orderId = DB::table('store_orders')->insertGetId([
        'encoder_id' => $user->id, 'supplier_id' => $supplierId, 'store_branch_id' => $stores['A']->id,
        'order_number' => 'T-1', 'order_date' => '2026-08-20', 'order_status' => 'committed',
    ]);

    test()->actingAs($user);

    return ['entity' => $entity, 'user' => $user, 'a' => $stores['A'], 'b' => $stores['B'], 'schedule' => $schedule, 'orderId' => $orderId];
}

function mecIrPage(): array
{
    $request = fn () => Request::create('/month-end-count', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);

    return app(MonthEndCountController::class)->index($request())->toResponse($request())->getData(true)['props'];
}

function mecIrDownload(StoreBranch $store)
{
    return app(MonthEndCountController::class)->downloadTemplate(Request::create('/month-end-count/download', 'GET', ['branch_id' => $store->id]));
}

function mecIrUpload(array $f, StoreBranch $store)
{
    return app(MonthEndCountController::class)->upload(Request::create(
        '/month-end-count/upload', 'POST', ['schedule_id' => $f['schedule']->id, 'branch_id' => $store->id], [],
        ['file' => UploadedFile::fake()->create('count.xlsx', 5, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')]
    ));
}

function mecIrFile(MonthEndCountIncidentReport $report, array $input)
{
    return app(MonthEndCountIncidentReportController::class)->file(
        Request::create('/month-end-count/incident-reports/'.$report->id, 'POST', $input), $report
    );
}

it('asks a store for an Incident Report on what it still had pending after the count date, and withholds the template and the upload until it is filed', function () {
    $f = mecIrFixture();
    ['a' => $a, 'b' => $b] = $f;

    // Seeing the pending opens the report: Store A only, with what was found.
    $page = mecIrPage();
    expect($page['incidentReports'])->toHaveCount(1);
    $shown = $page['incidentReports'][0];
    expect($shown['branch_id'])->toBe($a->id)
        ->and($shown['filed'])->toBeFalse()
        ->and($shown['blocks_template'])->toBeTrue()
        ->and($shown['count_label'])->toBe('August 2026')
        ->and($shown['pendings'])->toBe([['key' => 'orders_not_yet_received', 'label' => '1 order not yet received', 'open' => true]])
        ->and(array_keys($page['branchesAwaitingUpload']))->toBe([$b->id]);

    $report = MonthEndCountIncidentReport::sole();
    expect($report->number)->toBe('MEC-IR-'.str_pad((string) $report->id, 6, '0', STR_PAD_LEFT))
        ->and((int) $report->entity_id)->toBe($f['entity']->id);

    // The delivery is received at last. Nothing is pending any more, but the store still
    // owes its explanation: no upload form, no template, no upload.
    DB::table('store_orders')->where('id', $f['orderId'])->update(['order_status' => 'received']);
    Excel::fake();

    $page = mecIrPage();
    expect($page['uploadPendingBranches'])->toBe([])
        ->and(array_keys($page['branchesAwaitingUpload']))->toBe([$b->id])
        ->and($page['incidentReports'][0]['pendings'][0]['open'])->toBeFalse();

    expect(mecIrDownload($a))->toBeInstanceOf(RedirectResponse::class)
        ->and(session('errors')->get('download')[0])->toContain('File the Incident Report');
    expect(mecIrUpload($f, $a)->getTargetUrl())->not->toContain('review')
        ->and(session('errors')->get('error')[0])->toContain('Store A still had unfinished transactions after the MEC Scheduled Date (Aug 31, 2026)');

    // Store B owes nothing and is not held back.
    expect(mecIrUpload($f, $b)->getTargetUrl())->toBe(route('month-end-count.review', ['schedule' => $f['schedule']->id, 'branch' => $b->id]));

    // A report without a reason for every pending is refused.
    mecIrFile($report, ['reasons' => ['orders_not_yet_received' => ''], 'action_taken' => 'Followed up the supplier.']);
    expect(session('errors')->get('error')[0])->toContain('Give a reason for every pending')
        ->and($report->fresh()->isFiled())->toBeFalse();

    // Filed: everything was finished by then, so no target date is asked for.
    Carbon::setTestNow(Carbon::parse('2026-09-02 09:30', 'Asia/Manila'));
    mecIrFile($report, ['reasons' => ['orders_not_yet_received' => 'The supplier delivered two days late.'], 'action_taken' => 'Followed up the supplier.']);

    $report->refresh();
    expect($report->isFiled())->toBeTrue()
        ->and((int) $report->filed_by)->toBe($f['user']->id)
        ->and($report->filed_at->format('Y-m-d H:i'))->toBe('2026-09-02 09:30')
        ->and($report->target_date)->toBeNull()
        ->and($report->pendings)->toBe([[
            'key' => 'orders_not_yet_received', 'label' => '1 order not yet received', 'count' => 1,
            'reason' => 'The supplier delivered two days late.', 'open' => false,
        ]]);

    // A filed report is final.
    mecIrFile($report, ['reasons' => ['orders_not_yet_received' => 'Changed my mind.'], 'action_taken' => 'x']);
    expect(session('errors')->get('error')[0])->toBe('This Incident Report was already filed.')
        ->and($report->fresh()->pendings[0]['reason'])->toBe('The supplier delivered two days late.');

    // The store is let through, and the page links the report.
    $page = mecIrPage();
    expect(array_keys($page['branchesAwaitingUpload']))->toContain($a->id)
        ->and($page['incidentReports'][0]['filed'])->toBeTrue()
        ->and($page['incidentReports'][0]['filed_by'])->toBe('Sam Store')
        ->and($page['incidentReports'][0]['pdf_url'])->toBe(route('month-end-count.incident-reports.pdf', $report->id));

    expect(mecIrDownload($a))->not->toBeInstanceOf(RedirectResponse::class);
    expect(mecIrUpload($f, $a)->getTargetUrl())->toBe(route('month-end-count.review', ['schedule' => $f['schedule']->id, 'branch' => $a->id]));
});

it('takes a target date for what is still pending, and keeps on the report what was finished in between', function () {
    $f = mecIrFixture();
    $a = $f['a'];

    // A second pending shows up beside the order, then the order is received.
    mecIrPage();
    DB::table('wastages')->insert([
        'store_branch_id' => $a->id, 'wastage_no' => 'W-1', 'wastage_qty' => 1, 'approverlvl2_qty' => 1, 'cost' => 0, 'reason' => 'Test',
        'wastage_status' => 'pending', 'created_by' => $f['user']->id, 'created_at' => '2026-08-25 09:00:00', 'updated_at' => '2026-08-25 09:00:00',
    ]);
    mecIrPage();
    DB::table('store_orders')->where('id', $f['orderId'])->update(['order_status' => 'received']);

    $pendings = collect(mecIrPage()['incidentReports'][0]['pendings'])->pluck('open', 'key')->all();
    expect($pendings)->toBe(['orders_not_yet_received' => false, 'wastage_level1' => true]);

    $report = MonthEndCountIncidentReport::sole();
    $input = [
        'reasons' => ['orders_not_yet_received' => 'Late delivery.', 'wastage_level1' => 'The approver is on leave.'],
        'action_taken' => 'Asked the area manager to approve.',
    ];

    mecIrFile($report, $input);
    expect(session('errors')->get('error')[0])->toContain('Give the target date')
        ->and($report->fresh()->isFiled())->toBeFalse();

    mecIrFile($report, $input + ['target_date' => '2026-09-03']);
    $report->refresh();
    expect($report->isFiled())->toBeTrue()
        ->and($report->target_date->format('Y-m-d'))->toBe('2026-09-03')
        ->and(collect($report->pendings)->pluck('open', 'key')->all())->toBe(['orders_not_yet_received' => false, 'wastage_level1' => true]);

    // Filing lifts the report's hold only: the wastage still withholds the count as before.
    Excel::fake();
    mecIrUpload($f, $a);
    expect(session('errors')->get('error')[0])->toContain('Store A still has unfinished transactions');
});

it('asks for no report before the count date, or while the process is switched off', function () {
    $f = mecIrFixture();
    $a = $f['a'];

    // The day of the count: a pending is normal, nothing has to be explained yet.
    Carbon::setTestNow(Carbon::parse('2026-08-31 10:00', 'Asia/Manila'));
    expect(mecIrPage()['incidentReports'])->toBe([])
        ->and(MonthEndCountIncidentReport::count())->toBe(0);

    // The office saves the configuration with the process switched off.
    Permission::findOrCreate('manage month end count settings');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Asia/Manila'));
    $settings = MonthEndCountSetting::defaults();
    expect($settings['incident_report_required'])->toBeTrue();
    app(MonthEndScheduleController::class)->updateSettings(Request::create('/month-end-schedules/settings', 'PUT', [
        'incident_report_required' => false, 'upload_cutoff_time' => '23:59',
    ] + $settings));

    $service = app(MonthEndCountIncidentReportService::class);
    expect($service->enabled())->toBeFalse()
        ->and(mecIrPage()['incidentReports'])->toBe([])
        ->and(MonthEndCountIncidentReport::count())->toBe(0);

    // A report left open from when it was on holds nothing back while it is off.
    $report = MonthEndCountIncidentReport::create([
        'month_end_schedule_id' => $f['schedule']->id, 'branch_id' => $a->id, 'required_at' => Carbon::now(),
        'pendings' => [['key' => 'orders_not_yet_received', 'label' => '1 order not yet received', 'count' => 1]],
    ]);
    expect($service->problem($f['schedule'], $a->id))->toBeNull();

    MonthEndCountSetting::where('entity_id', $f['entity']->id)->update(['incident_report_required' => true]);
    expect($service->enabled())->toBeTrue()
        ->and($service->problem($f['schedule'], $a->id))->toContain('File the Incident Report');
});

it('prints a filed report as a PDF for its store and for the office, and lists it in Store Progress', function () {
    $f = mecIrFixture();
    $a = $f['a'];
    mecIrPage();
    $report = MonthEndCountIncidentReport::sole();
    $controller = app(MonthEndCountIncidentReportController::class);

    // Nothing to print until it is filed.
    expect(fn () => $controller->pdf($report))->toThrow(HttpException::class);

    $progress = fn () => collect(app(MonthEndScheduleController::class)->getDetails(Request::create('/details', 'GET'), $f['schedule'])->getData(true)['data'])
        ->pluck('incident_report', 'name')->all();
    expect($progress())->toBe(['Store A' => ['number' => $report->number, 'filed' => false, 'pdf_url' => null], 'Store B' => null]);

    mecIrFile($report, [
        'reasons' => ['orders_not_yet_received' => 'The supplier has not delivered.'],
        'action_taken' => 'Escalated to purchasing.', 'target_date' => '2026-09-04',
    ]);
    $report->refresh();

    $pdf = $controller->pdf($report);
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->headers->get('Content-Disposition'))->toContain('inline')->toContain($report->number.'.pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    expect($progress()['Store A'])->toBe([
        'number' => $report->number, 'filed' => true, 'pdf_url' => route('month-end-count.incident-reports.pdf', $report->id),
    ]);

    // Someone from another store cannot read or file it; the office can read it.
    $outsider = User::factory()->create();
    test()->actingAs($outsider);
    expect(fn () => $controller->pdf($report))->toThrow(HttpException::class)
        ->and(fn () => mecIrFile($report, ['reasons' => ['orders_not_yet_received' => 'x'], 'action_taken' => 'x']))->toThrow(HttpException::class);

    Permission::findOrCreate('view month end schedules');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $outsider->givePermissionTo('view month end schedules');
    expect(substr($controller->pdf($report)->getContent(), 0, 4))->toBe('%PDF');
});
